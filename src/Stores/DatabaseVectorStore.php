<?php

declare(strict_types=1);

namespace Prism\Memory\Stores;

use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Enums\Durability;
use Prism\Memory\Exceptions\EmbeddingSpaceMismatch;
use Prism\Memory\Support\BinarySignature;
use Prism\Memory\Support\IndexSettings;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorMatch;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;
use stdClass;

/**
 * Vectors in the database, because that is what every Laravel app already has.
 *
 * The harness learned this the expensive way. It defaulted its ephemeral store
 * to Redis, and a fresh install threw a raw connection error the first time a
 * session wrote anything, on a machine that had never claimed to run Redis. A
 * default that assumes infrastructure is not a default. A real vector database
 * is strictly better than this driver at scale and is where a large
 * installation should end up — as an opt-in, behind {@see VectorStore}.
 *
 * Always Durable: rows survive a deploy, which is the point of it existing.
 *
 * ## How a search stays affordable
 *
 * Not by reading fewer rows — by reading much smaller ones. Each vector carries
 * a 32-byte signature of its direction; a search ranks the whole collection on
 * those and then reads the full vectors of only the best few hundred, scoring
 * those exactly. Every score a caller sees is a real cosine. What is
 * approximate is which memories were considered.
 *
 * This is linear in the size of the collection with a constant a few hundred
 * times smaller, and it is NOT sublinear. {@see BinarySignature} carries the
 * measured reason a bucketed index does not get there.
 *
 * ## What is stored, and why in that form
 *
 * The vector is base64 of packed doubles in a text column, not a binary column
 * and not JSON. Binary columns are where the three databases stop agreeing —
 * PDO hands back a stream for Postgres `bytea` and a string everywhere else, so
 * the driver would need a per-database branch on the read path, which is a
 * per-database bug on the read path. Text is text on all three. See
 * {@see Vector} for why not JSON and why not float32.
 *
 * Metadata is always written as a JSON OBJECT, including when it is empty. PHP
 * cannot tell `{}` from `[]` — both decode to the same array — so `json_encode`
 * of an empty metadata map emits `[]` and of a populated one emits `{}`, which
 * is the same column changing JSON type with its contents. That is finding F-3
 * in the conformance corpus, and the cast here is the fix that finding
 * recommends. It costs nothing, and it means a row written by PHP reads back
 * correctly in TypeScript and Python, which is not otherwise true.
 */
final class DatabaseVectorStore implements VectorStore
{
    private readonly BinarySignature $signatures;

    /**
     * How many rows the last search ranked before scoring anything exactly.
     *
     * Exposed so a benchmark can assert on the shape of the work rather than on
     * a wall-clock time, which is the one number that means something different
     * on every machine it is measured on.
     */
    public int $lastRanked = 0;

    /**
     * How many full vectors the last search read.
     */
    public int $lastScored = 0;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IndexSettings $index,
        private readonly string $table = 'memory_vectors',
    ) {
        $this->signatures = new BinarySignature($index->seed, $index->bits);
    }

    #[\Override]
    public function upsert(iterable $records): void
    {
        foreach ($records as $record) {
            $now = Carbon::now();

            $this->vectors()->updateOrInsert(
                ['collection' => $record->collection, 'record_id' => $record->id],
                [
                    'content' => $record->content,
                    'vector' => $record->vector?->pack(),
                    'dimensions' => $record->vector?->dimensions(),
                    'signature' => $record->vector instanceof Vector
                        ? $this->signatures->of($record->vector)
                        : null,
                    'space' => $record->space,
                    // Cast to an object so an empty map serialises as `{}`
                    // rather than `[]`. See the class docblock.
                    'metadata' => json_encode((object) $record->metadata),
                    'occurred_at' => $record->occurredAt ?? $now,
                    'expires_at' => $record->expiresAt,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    #[\Override]
    public function search(VectorQuery $query): array
    {
        $rows = $this->index->isApproximate()
            ? $this->rankedCandidates($query)
            : $this->everyVector($query);

        $this->lastScored = count($rows);

        if ($rows === []) {
            // Only on the path that found nothing is it worth asking whether
            // the collection holds vectors from a DIFFERENT embedding model.
            // The check is a second query, so it does not belong on the happy
            // path — but an empty result caused by someone swapping
            // `text-embedding-3-small` for `-large` looks exactly like an empty
            // result caused by having no memories, and one of those is a bug an
            // application will spend a day on.
            $this->assertSpaceMatches($query);

            return [];
        }

        $matches = [];

        foreach ($rows as $row) {
            $vector = Vector::unpack((string) $row->vector);

            if ($vector->dimensions() !== $query->vector->dimensions()) {
                // Reachable only if a row's `space` matches while its width does
                // not, which means the space string is lying. Skipping would
                // hide that; comparing would throw from inside a loop with no
                // context. Name the record instead.
                throw EmbeddingSpaceMismatch::dimensions(
                    (string) $row->record_id,
                    $vector->dimensions(),
                    $query->vector->dimensions(),
                );
            }

            $similarity = $query->vector->cosine($vector);

            if ($query->minSimilarity !== null && $similarity < $query->minSimilarity) {
                continue;
            }

            $matches[] = new VectorMatch(
                collection: (string) $row->collection,
                recordId: (string) $row->record_id,
                content: (string) $row->content,
                metadata: $this->decodeMetadata($row->metadata),
                similarity: $similarity,
                occurredAt: Carbon::parse($row->occurred_at),
            );
        }

        usort($matches, fn (VectorMatch $a, VectorMatch $b): int => $b->similarity <=> $a->similarity);

        return array_slice($matches, 0, $query->limit);
    }

    #[\Override]
    public function forget(string $collection, array $recordIds): int
    {
        if ($recordIds === []) {
            return 0;
        }

        return $this->vectors()
            ->where('collection', $collection)
            ->whereIn('record_id', $recordIds)
            ->delete();
    }

    #[\Override]
    public function purge(string $collection): int
    {
        return $this->vectors()->where('collection', $collection)->delete();
    }

    #[\Override]
    public function purgeOccurredBefore(string $collection, DateTimeInterface $before): int
    {
        return $this->vectors()
            ->where('collection', $collection)
            ->where('occurred_at', '<', $before)
            ->delete();
    }

    #[\Override]
    public function count(string $collection, bool $embeddedOnly = false): int
    {
        $query = $this->vectors()->where('collection', $collection);

        if ($embeddedOnly) {
            $query->whereNotNull('vector');
        }

        return $query->count();
    }

    #[\Override]
    public function unembedded(string $collection, int $limit = 100): array
    {
        $rows = $this->vectors()
            ->where('collection', $collection)
            ->whereNull('vector')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $records = [];

        foreach ($rows as $row) {
            $records[] = new VectorRecord(
                collection: $collection,
                id: (string) $row->record_id,
                content: (string) $row->content,
                vector: null,
                space: (string) $row->space,
                metadata: $this->decodeMetadata($row->metadata),
                occurredAt: Carbon::parse($row->occurred_at),
                expiresAt: $row->expires_at === null ? null : Carbon::parse($row->expires_at),
            );
        }

        return $records;
    }

    #[\Override]
    public function durability(): Durability
    {
        return Durability::Durable;
    }

    /**
     * Remove rows whose retention window has passed.
     *
     * Not on the contract, because expiry is enforced on READ — a store cannot
     * serve an expired row whether or not anything has pruned it, which is the
     * property that matters. This is bookkeeping: reclaiming the space, and
     * keeping expired rows from occupying candidate slots on every search.
     */
    public function purgeExpired(?string $collection = null): int
    {
        $query = $this->vectors()->whereNotNull('expires_at')->where('expires_at', '<=', Carbon::now());

        if ($collection !== null) {
            $query->where('collection', $collection);
        }

        return $query->delete();
    }

    /**
     * Rank the collection on signatures, then read only the best vectors.
     *
     * The two-column select is the entire optimisation. `id` and `signature`
     * together are about forty bytes; the row they stand for is twelve
     * kilobytes. Adding `content` here — tempting, since it is needed later —
     * would put the collection's whole text through the ranking pass and undo
     * most of the saving.
     *
     * @return list<stdClass>
     */
    private function rankedCandidates(VectorQuery $query): array
    {
        $target = hex2bin($this->signatures->of($query->vector));

        if ($target === false) {
            return [];
        }

        $ranked = [];

        foreach ($this->readable($query)->get(['id', 'signature']) as $row) {
            if (! is_string($row->signature)) {
                continue;
            }

            $signature = hex2bin($row->signature);

            if ($signature === false || strlen($signature) !== strlen($target)) {
                // A row written under a different signature width. Skipping it
                // is the only option that does not fail every search in the
                // collection during a re-index, and the README says a width
                // change needs one.
                continue;
            }

            $ranked[(int) $row->id] = BinarySignature::distance($target, $signature);
        }

        $this->lastRanked = count($ranked);

        if ($ranked === []) {
            return [];
        }

        // Ascending: fewer disagreeing bits is a smaller angle.
        asort($ranked);

        /** @var list<stdClass> */
        return $this->vectors()
            ->whereIn('id', array_slice(array_keys($ranked), 0, $this->index->candidates))
            ->get()
            ->all();
    }

    /**
     * @return list<stdClass>
     */
    private function everyVector(VectorQuery $query): array
    {
        $rows = $this->readable($query)
            ->orderByDesc('occurred_at')
            ->limit($this->index->exactScanLimit)
            ->get()
            ->all();

        $this->lastRanked = count($rows);

        /** @var list<stdClass> */
        return $rows;
    }

    /**
     * @return Builder
     */
    private function readable(VectorQuery $query)
    {
        $builder = $this->vectors()
            ->whereIn('collection', $query->collections)
            ->where('space', $query->space)
            ->whereNotNull('vector')
            // Expiry is enforced here rather than by a sweeper, so a memory past
            // its retention window can never be recalled merely because nothing
            // has pruned it yet.
            ->where(function (Builder $expiry): void {
                $expiry->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            });

        foreach ($query->filter as $key => $value) {
            if (is_array($value)) {
                $builder->whereIn('metadata->'.$key, $value);

                continue;
            }

            $builder->where('metadata->'.$key, $value);
        }

        return $builder;
    }

    private function assertSpaceMatches(VectorQuery $query): void
    {
        /** @var mixed $other */
        $other = $this->vectors()
            ->whereIn('collection', $query->collections)
            ->whereNotNull('vector')
            ->where('space', '!=', $query->space)
            ->value('space');

        if (is_string($other)) {
            throw EmbeddingSpaceMismatch::collection($query->collections, $other, $query->space);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, scalar|null> $decoded */
        return $decoded;
    }

    /**
     * @return Builder
     */
    private function vectors()
    {
        return $this->connection->table($this->table);
    }
}
