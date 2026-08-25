<?php

declare(strict_types=1);

namespace Prism\Memory\Contracts;

use DateTimeInterface;
use Prism\Memory\Enums\Durability;
use Prism\Memory\ValueObjects\VectorMatch;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;

/**
 * Where embedded text lives, for the whole Prism ecosystem.
 *
 * This contract is the reason `prism-memory` exists before `prism-rag` rather
 * than the other way round. Memory needed a vector store first; defining a
 * second one in `prism-rag` would mean an application that installs both
 * embeds its text twice, pays twice, and searches two indexes that disagree.
 * `specs/prism-rag.md` records that it consumes this rather than defining its
 * own, and decision 0008 records why: two abstractions for one job is exactly
 * what turns an ecosystem into a collection.
 *
 * Seven methods, and each earns its place from a requirement one of the two
 * packages actually has:
 *
 *  - `upsert` is keyed on a CALLER-OWNED id, which is what makes both packages'
 *    writes idempotent. `prism-rag` states outright that re-ingesting an
 *    unchanged document must not duplicate it; deriving the id from a digest of
 *    the content gets that for free, and the alternative — a store-assigned id
 *    plus a de-duplication query — is a race between two workers.
 *  - `unembedded` exists because neither package can afford to embed inside the
 *    request. A record is written immediately and embedded afterwards, which
 *    means both need to find the records that never got their vector, whether
 *    because the queue was empty or because a worker died.
 *  - `purgeOccurredBefore` names the axis it cuts on IN THE METHOD NAME. A
 *    `purge($collection, $before)` reads as "drop rows ingested before X" and
 *    would mean "drop records whose subject matter is dated before X" — for
 *    `prism-rag` those are the document's date and the import's date, and the
 *    difference only ever shows up as missing data. An ingestion-time purge, if
 *    it is ever wanted, is a second method with its own name and not a flag.
 *  - `durability` is not inferable. Only the operator knows whether a given
 *    backend survives a deploy, and memory that a deploy can clear is a cache
 *    that must say so.
 *
 * What is deliberately NOT here: index tuning, embedding, chunking, and
 * anything resembling a query builder. A store takes vectors and returns
 * neighbours. Everything else is the consuming package's business.
 */
interface VectorStore
{
    /**
     * Write records, replacing any with the same (collection, id).
     *
     * A record whose `vector` is null is stored unembedded and is invisible to
     * `search` until a vector arrives. That state is not an edge case — it is
     * the normal path when embedding is queued.
     *
     * @param  iterable<VectorRecord>  $records
     */
    public function upsert(iterable $records): void;

    /**
     * Nearest neighbours, most similar first.
     *
     * A query may name one collection or several, and several must be ONE
     * query rather than a loop. Searching three corpora as three round trips
     * and merging in PHP is a cost paid on every search, where a driver that
     * knows it is being asked for three can answer with a single
     * `WHERE collection IN (...)`. Every result carries the collection it came
     * from, so a merged set stays attributable.
     *
     * Implementations MAY be approximate, and an approximate implementation
     * must say so in its own documentation rather than in this one — a caller
     * that believes it is getting exact nearest neighbours and is not will
     * conclude the embeddings are bad.
     *
     * @return list<VectorMatch>
     */
    public function search(VectorQuery $query): array;

    /**
     * Remove specific records. Returns how many rows actually went.
     *
     * @param  list<string>  $recordIds
     */
    public function forget(string $collection, array $recordIds): int;

    /**
     * Remove a whole collection.
     *
     * Returns how many rows went, because "delete this person's memories" is an
     * assertion someone may later have to evidence, and a void return cannot be
     * evidence of anything.
     */
    public function purge(string $collection): int;

    /**
     * Remove everything in a collection whose `occurredAt` is before $before.
     *
     * The name states the axis on purpose. `occurredAt` is when the remembered
     * thing HAPPENED — the turn, or the document's date — and not when the row
     * was written. For a backfilled corpus those differ by years, and a caller
     * who read a bare `purge($collection, $before)` as "drop what I ingested
     * before X" would delete a different set than they intended and find out by
     * missing it.
     */
    public function purgeOccurredBefore(string $collection, DateTimeInterface $before): int;

    /**
     * How many records a collection holds.
     *
     * $embeddedOnly separates "how much is stored" from "how much is
     * searchable", which are different numbers whenever embedding is queued.
     */
    public function count(string $collection, bool $embeddedOnly = false): int;

    /**
     * Records written but never embedded, oldest first.
     *
     * @return list<VectorRecord>
     */
    public function unembedded(string $collection, int $limit = 100): array;

    /**
     * Whether this store's contents survive a deploy.
     *
     * Declared by the implementation, never inferred. See {@see Durability}.
     */
    public function durability(): Durability;
}
