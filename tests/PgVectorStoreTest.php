<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Prism\Memory\Enums\Durability;
use Prism\Memory\Exceptions\EmbeddingSpaceMismatch;
use Prism\Memory\Stores\PgVectorStore;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;

/*
|--------------------------------------------------------------------------
| pgvector, against a real Postgres
|--------------------------------------------------------------------------
|
| SKIPPED unless MEMORY_PGVECTOR_DSN is set, because pgvector cannot be faked
| into SQLite and a suite that quietly passed without it would be asserting
| nothing about the driver it names. Skipping is stated in the skip message so
| a green run cannot be mistaken for a verified one.
|
|     MEMORY_PGVECTOR_DSN="pgsql:host=127.0.0.1;port=5432;dbname=app" \
|     MEMORY_PGVECTOR_USER=app MEMORY_PGVECTOR_PASSWORD=secret \
|     vendor/bin/pest tests/PgVectorStoreTest.php
|
| The width is 3 throughout. Small enough to reason about by hand, and the
| cosines below are checkable on paper — a test whose expected values came out
| of the code it is testing proves only that the code is deterministic.
|
*/

const PGV_DIMENSIONS = 3;

function pgvAvailable(): bool
{
    return is_string(env('MEMORY_PGVECTOR_DSN')) && env('MEMORY_PGVECTOR_DSN') !== '';
}

function pgvConnect(): void
{
    config()->set('database.connections.pgvector', [
        'driver' => 'pgsql',
        'host' => pgvDsnPart('host'),
        'port' => pgvDsnPart('port'),
        'database' => pgvDsnPart('dbname'),
        'username' => (string) env('MEMORY_PGVECTOR_USER'),
        'password' => (string) env('MEMORY_PGVECTOR_PASSWORD'),
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'sslmode' => 'prefer',
    ]);
}

function pgvDsnPart(string $key): string
{
    preg_match('/'.$key.'=([^;]+)/', (string) env('MEMORY_PGVECTOR_DSN'), $matches);

    return $matches[1] ?? '';
}

function pgvStore(): PgVectorStore
{
    return new PgVectorStore(
        connection: DB::connection('pgvector'),
        dimensions: PGV_DIMENSIONS,
        table: 'memory_vectors_pgvector_test',
    );
}

function pgvMigrate(): void
{
    $connection = DB::connection('pgvector');
    $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
    $connection->statement('DROP TABLE IF EXISTS memory_vectors_pgvector_test');
    $connection->statement(
        'CREATE TABLE memory_vectors_pgvector_test ('
        .'id bigserial primary key,'
        .'collection varchar(255) not null,'
        .'record_id varchar(191) not null,'
        .'content text not null,'
        .'space varchar(191) not null,'
        .'metadata json not null,'
        .'occurred_at timestamp not null,'
        .'expires_at timestamp null,'
        .'created_at timestamp null,'
        .'updated_at timestamp null,'
        .'embedding vector('.PGV_DIMENSIONS.') null,'
        .'unique (collection, record_id))'
    );
    $connection->statement(
        'CREATE INDEX ON memory_vectors_pgvector_test '
        .'USING hnsw (embedding vector_cosine_ops) WHERE embedding IS NOT NULL'
    );
}

function pgvRecord(string $id, string $content, ?Vector $vector = null, array $metadata = [], ?Carbon $at = null, ?Carbon $expires = null): VectorRecord
{
    return new VectorRecord(
        collection: 'c',
        id: $id,
        content: $content,
        vector: $vector,
        space: 'test:space',
        metadata: $metadata,
        occurredAt: $at,
        expiresAt: $expires,
    );
}

beforeEach(function (): void {
    if (! pgvAvailable()) {
        $this->markTestSkipped('MEMORY_PGVECTOR_DSN is not set — the pgvector driver was NOT exercised.');
    }

    pgvConnect();
    pgvMigrate();
});

it('ranks by real cosine similarity, nearest first', function (): void {
    $store = pgvStore();

    $store->upsert([
        pgvRecord('same', 'identical direction', Vector::of([1.0, 0.0, 0.0])),
        pgvRecord('near', 'mostly the same', Vector::of([0.9, 0.1, 0.0])),
        pgvRecord('orthogonal', 'unrelated', Vector::of([0.0, 1.0, 0.0])),
    ]);

    $matches = $store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'test:space'));

    expect($matches)->toHaveCount(3)
        ->and($matches[0]->recordId)->toBe('same')
        ->and($matches[1]->recordId)->toBe('near')
        ->and($matches[2]->recordId)->toBe('orthogonal')
        // Checkable on paper: identical direction is 1, orthogonal is 0.
        ->and($matches[0]->similarity)->toBeGreaterThan(0.999)
        ->and($matches[2]->similarity)->toBeLessThan(0.001);
});

it('distinguishes a metadata key that is null from one that is absent', function (): void {
    // THE case the store contract names as discriminating. A driver that
    // dropped null keys on write, or invented them on read, passes every test
    // that only checks a populated map.
    $store = pgvStore();

    $store->upsert([pgvRecord('a', 'hello', Vector::of([1.0, 0.0, 0.0]), ['present' => null, 'other' => 1])]);

    $metadata = $store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'test:space'))[0]->metadata;

    expect($metadata)->toHaveKey('present')
        ->and($metadata['present'])->toBeNull()
        ->and($metadata)->not->toHaveKey('absent');
});

it('keeps a null under the reserved source_ prefix, which Provenance collapses but storage must not', function (): void {
    // The crossing described in the contract: Provenance reads absent and null
    // as the same state, and collapsing them at the STORAGE layer because of
    // that is the bug. A suite testing only an ordinary key misses it.
    $store = pgvStore();

    $store->upsert([pgvRecord('a', 'hello', Vector::of([1.0, 0.0, 0.0]), ['source_page' => null])]);

    $metadata = $store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'test:space'))[0]->metadata;

    expect($metadata)->toHaveKey('source_page')
        ->and($metadata['source_page'])->toBeNull();
});

it('is idempotent on the caller-owned id', function (): void {
    $store = pgvStore();

    $store->upsert([pgvRecord('a', 'first', Vector::of([1.0, 0.0, 0.0]))]);
    $store->upsert([pgvRecord('a', 'second', Vector::of([1.0, 0.0, 0.0]))]);

    expect($store->count('c'))->toBe(1)
        ->and($store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'test:space'))[0]->content)
        ->toBe('second');
});

it('hides an unembedded record from search and offers it for embedding', function (): void {
    $store = pgvStore();

    $store->upsert([
        pgvRecord('embedded', 'searchable', Vector::of([1.0, 0.0, 0.0])),
        pgvRecord('waiting', 'not yet'),
    ]);

    expect($store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'test:space')))->toHaveCount(1)
        ->and($store->count('c'))->toBe(2)
        ->and($store->count('c', embeddedOnly: true))->toBe(1);

    $pending = $store->unembedded('c');

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->id)->toBe('waiting')
        ->and($pending[0]->vector)->toBeNull();
});

it('never recalls a memory past its retention window, pruned or not', function (): void {
    $store = pgvStore();

    $store->upsert([
        pgvRecord('live', 'current', Vector::of([1.0, 0.0, 0.0])),
        pgvRecord('dead', 'expired', Vector::of([1.0, 0.0, 0.0]), expires: Carbon::now()->subDay()),
    ]);

    $matches = $store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'test:space'));

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->recordId)->toBe('live');
});

it('searches several collections in one query and keeps every result attributable', function (): void {
    $store = pgvStore();

    $store->upsert([
        new VectorRecord('handbook', 'a', 'from the handbook', Vector::of([1.0, 0.0, 0.0]), 'test:space'),
        new VectorRecord('contracts', 'b', 'from the contracts', Vector::of([0.9, 0.1, 0.0]), 'test:space'),
    ]);

    $matches = $store->search(new VectorQuery(['handbook', 'contracts'], Vector::of([1.0, 0.0, 0.0]), 'test:space'));

    expect($matches)->toHaveCount(2)
        ->and(array_map(fn ($m): string => $m->collection, $matches))
        ->toBe(['handbook', 'contracts']);
});

it('names the record when a stored width contradicts its declared space', function (): void {
    expect(fn (): mixed => pgvStore()->upsert([pgvRecord('wrong', 'x', Vector::of([1.0, 0.0]))]))
        ->toThrow(EmbeddingSpaceMismatch::class, 'wrong');
});

it('reports an empty collection as an embedding space mismatch when one exists', function (): void {
    $store = pgvStore();

    $store->upsert([pgvRecord('a', 'stored under another model', Vector::of([1.0, 0.0, 0.0]))]);

    expect(fn (): mixed => $store->search(new VectorQuery(['c'], Vector::of([1.0, 0.0, 0.0]), 'other:space')))
        ->toThrow(EmbeddingSpaceMismatch::class);
});

it('returns how many rows went, because a deletion may have to be evidenced', function (): void {
    $store = pgvStore();

    $store->upsert([
        pgvRecord('a', 'one', Vector::of([1.0, 0.0, 0.0]), at: Carbon::parse('2020-01-01')),
        pgvRecord('b', 'two', Vector::of([1.0, 0.0, 0.0]), at: Carbon::parse('2026-01-01')),
    ]);

    expect($store->purgeOccurredBefore('c', Carbon::parse('2024-01-01')))->toBe(1)
        ->and($store->count('c'))->toBe(1)
        ->and($store->forget('c', ['b']))->toBe(1)
        ->and($store->count('c'))->toBe(0);
});

it('purges a whole collection and reports the count', function (): void {
    $store = pgvStore();

    $store->upsert([
        pgvRecord('a', 'one', Vector::of([1.0, 0.0, 0.0])),
        pgvRecord('b', 'two', Vector::of([0.9, 0.1, 0.0])),
    ]);

    expect($store->purge('c'))->toBe(2)
        ->and($store->count('c'))->toBe(0);
});

it('declares itself durable', function (): void {
    expect(pgvStore()->durability())->toBe(Durability::Durable);
});
