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
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorMatch;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;
use stdClass;

/**
 * Vectors in Postgres, indexed by pgvector — the driver the README's ceiling
 * has been pointing at.
 *
 * WHY THIS EXISTS. `DatabaseVectorStore` documents, honestly, that it is not
 * sublinear: it reads one small row per memory in the collection and says that
 * past roughly twenty thousand in a single collection you should register a
 * real vector database. It then showed you `new PgVectorStore(...)` in an
 * example, and no such class shipped. A documented escape hatch with nothing
 * behind it is worse than an acknowledged limit, because a reader plans around
 * it. A consumer with a real corpus found this and offered to write the driver
 * themselves; that offer being necessary was the defect.
 *
 * WHAT CHANGES, AND WHAT DELIBERATELY DOES NOT. The search is genuinely
 * sublinear here — HNSW walks a graph instead of ranking every row — so the
 * signature machinery {@see BinarySignature} exists to make a full scan
 * affordable is simply absent. Everything a caller can observe is unchanged:
 * the same eight methods, the same `VectorMatch` carrying a real cosine, the
 * same absent-versus-null metadata rule, the same refusal to compare vectors
 * from two embedding spaces.
 *
 * ## Approximate, and saying so here as the contract requires
 *
 * HNSW returns approximate nearest neighbours. Recall is governed by
 * `hnsw.ef_search`, set per search from {@see $efSearch}; raising it costs
 * latency and finds more of the true neighbours. The scores are exact cosines
 * of the vectors it did find — what is approximate is which rows were
 * considered, exactly as with the portable driver, for a different reason.
 *
 * ## One width per table, checked rather than discovered
 *
 * pgvector indexes a fixed-width column, so this table holds one embedding
 * width. A record of another width is refused by name at write time rather
 * than surfacing as a Postgres type error from inside a batch, because the
 * cause — a model whose output width changed under a name that did not — is
 * not readable from `expected 1536 dimensions, not 3072`.
 *
 * ## Not the only way to bring your own store
 *
 * This ships as a driver, NOT as the escape hatch itself.
 * `VectorStoreManager::extend()` takes any {@see VectorStore} — Qdrant,
 * Pinecone, Weaviate, Milvus, something bespoke — and that path is unchanged
 * and unprivileged. pgvector is here because it needs no infrastructure an
 * application does not already run, which makes it the cheapest first step off
 * the portable driver, not because it is the intended destination for everyone.
 */
final class PgVectorStore implements VectorStore
{
    /**
     * @param  int  $dimensions  The width of the indexed column. Must match the embedding model.
     * @param  int  $efSearch  HNSW candidate list size. Higher is more accurate and slower.
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $dimensions,
        private readonly string $table = 'memory_vectors_pgvector',
        private readonly int $efSearch = 100,
    ) {}

    #[\Override]
    public function upsert(iterable $records): void
    {
        foreach ($records as $record) {
            if ($record->vector instanceof Vector && $record->vector->dimensions() !== $this->dimensions) {
                // Refused by name, before Postgres refuses it by type. The
                // database's own message names two integers and no record.
                throw EmbeddingSpaceMismatch::dimensions(
                    $record->id,
                    $record->vector->dimensions(),
                    $this->dimensions,
                );
            }

            $now = Carbon::now();

            $this->vectors()->updateOrInsert(
                ['collection' => $record->collection, 'record_id' => $record->id],
                [
                    'content' => $record->content,
                    'embedding' => $record->vector instanceof Vector
                        ? $this->literal($record->vector)
                        : null,
                    'space' => $record->space,
                    // Cast to an object so an empty map is `{}` and not `[]`.
                    // jsonb preserves an explicit null and the absence of a key
                    // as different states on its own, which is the storage-layer
                    // half of the rule in {@see VectorStore}; nothing here may
                    // normalise a `source_*` null away.
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
        if ($query->vector->dimensions() !== $this->dimensions) {
            throw EmbeddingSpaceMismatch::dimensions(
                '(search vector)',
                $this->dimensions,
                $query->vector->dimensions(),
            );
        }

        // Per-session, not per-connection: a pooled connection carrying a
        // previous caller's ef_search would make recall depend on who ran last.
        $this->connection->statement('SET LOCAL hnsw.ef_search = '.$this->efSearch);

        $target = $this->literal($query->vector);

        // `<=>` is cosine DISTANCE, so 1 - it is the similarity. Ordering by the
        // operator rather than by the computed column is what lets the planner
        // use the HNSW index; ordering by `similarity DESC` does not.
        $rows = $this->readable($query)
            ->selectRaw('collection, record_id, content, metadata, occurred_at, 1 - (embedding <=> ?) as similarity', [$target])
            ->orderByRaw('embedding <=> ?', [$target])
            ->limit($query->limit)
            ->get()
            ->all();

        if ($rows === []) {
            // Only worth a second query on the path that found nothing: an empty
            // result from swapping the embedding model looks exactly like an
            // empty result from having no memories, and one of those is a bug.
            $this->assertSpaceMatches($query);

            return [];
        }

        $matches = [];

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $similarity = (float) $row->similarity;

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

        return $matches;
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
            $query->whereNotNull('embedding');
        }

        return $query->count();
    }

    #[\Override]
    public function unembedded(string $collection, int $limit = 100): array
    {
        $rows = $this->vectors()
            ->where('collection', $collection)
            ->whereNull('embedding')
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
     * Bookkeeping, exactly as on the portable driver: expiry is enforced on
     * READ, so a row past its window can never be recalled whether or not
     * anything has pruned it. This reclaims the space.
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
     * @return Builder
     */
    private function readable(VectorQuery $query)
    {
        $builder = $this->vectors()
            ->whereIn('collection', $query->collections)
            ->where('space', $query->space)
            ->whereNotNull('embedding')
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
            ->whereNotNull('embedding')
            ->where('space', '!=', $query->space)
            ->value('space');

        if (is_string($other)) {
            throw EmbeddingSpaceMismatch::collection($query->collections, $other, $query->space);
        }
    }

    /**
     * A vector as the text form pgvector parses: `[0.1,0.2,0.3]`.
     *
     * `json_encode` rather than hand-formatting each float. The hand-rolled
     * version here trimmed trailing zeroes to keep the literal short and turned
     * 0.0 into an EMPTY STRING — `rtrim('0', '0')` is `''` — so a vector with a
     * zero component became `[1,,]` and Postgres rejected the whole insert.
     * PHP's float encoding already round-trips a double, and shortening the
     * literal was never worth a formatting routine of our own.
     *
     * Bound as a parameter everywhere it is used, never interpolated.
     *
     * Note that pgvector stores single-precision floats, so a component is
     * narrowed on write regardless of what is sent. Cosines come back a shade
     * different from the portable driver's for that reason, not because either
     * is computing it wrong.
     */
    private function literal(Vector $vector): string
    {
        return (string) json_encode($vector->toArray());
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
