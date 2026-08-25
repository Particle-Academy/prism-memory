<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Prism\Memory\Enums\Durability;
use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\Exceptions\EmbeddingSpaceMismatch;
use Prism\Memory\Exceptions\UnstorableMemory;
use Prism\Memory\Stores\DatabaseVectorStore;
use Prism\Memory\Support\IndexSettings;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;

/*
|--------------------------------------------------------------------------
| Round trip through storage
|--------------------------------------------------------------------------
|
| The corpus category exists partly because of this package. `prism-harness`
| stored correctly, loaded correctly, and produced an array where a value
| object belonged — and the axes it broke on are absent-vs-null, enums and
| floats. So those are what is tested here, and the assertions are on identity
| rather than on presence.
|
*/

function store(string $strategy = IndexSettings::STRATEGY_SIGNATURE): DatabaseVectorStore
{
    return new DatabaseVectorStore(
        connection: DB::connection(),
        index: new IndexSettings(strategy: $strategy),
    );
}

function record(string $id, string $content, ?Vector $vector = null, array $metadata = [], ?Carbon $at = null, ?Carbon $expires = null): VectorRecord
{
    return new VectorRecord(
        collection: 'c',
        id: $id,
        content: $content,
        vector: $vector ?? Vector::of([1.0, 0.0, 0.0, 0.0]),
        space: 'test:space',
        metadata: $metadata,
        occurredAt: $at,
        expiresAt: $expires,
    );
}

it('returns a vector from storage bit-identical to the one that went in', function (): void {
    // Not "a similar vector". The same doubles. A float32 column, a JSON
    // encoding at the wrong precision, or a text column that trimmed would all
    // pass a test that only asked whether something came back.
    $values = [0.1, -1 / 3, 1.0e-300, PHP_FLOAT_EPSILON, 0.30000000000000004, 9.87654321e-5];
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'hello', Vector::of($values))]);

    $matches = $store->search(new VectorQuery('c', Vector::of($values), 'test:space'));

    // Reconstructed from the stored bytes: scoring identically to itself is
    // only possible if every component survived.
    expect($matches)->toHaveCount(1)
        ->and($matches[0]->similarity)->toBe(1.0);
});

it('distinguishes a metadata key that is null from one that is absent', function (): void {
    // The first divergence axis named in decision 0007. A store that dropped
    // nulls on write, or invented them on read, passes every test that only
    // asks whether the key it set came back.
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'hello', metadata: ['present' => null, 'other' => 1])]);

    $metadata = $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space'))[0]->metadata;

    expect($metadata)->toHaveKey('present')
        ->and($metadata['present'])->toBeNull()
        ->and($metadata)->not->toHaveKey('absent');
});

it('keeps ints, floats and bools as ints, floats and bools', function (): void {
    // JSON is the axis here: a store that round-tripped through string casts
    // would return "1" for true and "0.25" for 0.25, and every metadata filter
    // written against them would silently stop matching.
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'hello', metadata: [
        'int' => 42,
        'float' => 0.1,
        'true' => true,
        'false' => false,
        'zero' => 0,
        'empty' => '',
    ])]);

    $metadata = $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space'))[0]->metadata;

    expect($metadata['int'])->toBe(42)
        ->and($metadata['float'])->toBe(0.1)
        ->and($metadata['true'])->toBeTrue()
        ->and($metadata['false'])->toBeFalse()
        ->and($metadata['zero'])->toBe(0)
        ->and($metadata['empty'])->toBe('');
});

it('rebuilds a backed enum as an enum rather than as its backing string', function (): void {
    // The harness's defect in miniature. Metadata carries scalars, so an enum
    // goes in as its value — and what matters is that the CALLER can get the
    // enum back. `tryFrom` returning null here would be an unrecognised kind
    // silently degrading to a default.
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'hello', metadata: ['kind' => MemoryKind::Observation->value])]);

    $metadata = $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space'))[0]->metadata;

    expect(MemoryKind::tryFrom((string) $metadata['kind']))->toBe(MemoryKind::Observation);
});

it('writes empty metadata as a JSON object and not as a JSON array', function (): void {
    // Finding F-3. PHP cannot tell `{}` from `[]` — both decode to the same
    // array — so nothing inside PHP can catch this, and it only surfaces when
    // `prism-ts` or `prism-py` reads the row and gets a list where a map
    // belongs. Asserted against the RAW column for that reason.
    store()->upsert([record('a', 'hello', metadata: [])]);

    expect(DB::table('memory_vectors')->where('record_id', 'a')->value('metadata'))->toBe('{}');
});

/*
|--------------------------------------------------------------------------
| Identity and idempotency
|--------------------------------------------------------------------------
*/

it('replaces rather than duplicates when the same id is written twice', function (): void {
    $store = store();

    $store->upsert([record('a', 'first')]);
    $store->upsert([record('a', 'second')]);

    expect($store->count('c'))->toBe(1)
        ->and(DB::table('memory_vectors')->where('record_id', 'a')->value('content'))->toBe('second');
});

it('rewrites the signature when a record vector changes', function (): void {
    // A stale signature does not fail — it makes the record a candidate for
    // queries it no longer resembles, and a non-candidate for the ones it does.
    $store = store();

    $store->upsert([record('a', 'first', Vector::of([1.0, 0.0, 0.0, 0.0]))]);
    $before = DB::table('memory_vectors')->where('record_id', 'a')->value('signature');

    $store->upsert([record('a', 'first', Vector::of([0.0, 0.0, 0.0, 1.0]))]);

    expect(DB::table('memory_vectors')->where('record_id', 'a')->value('signature'))->not->toBe($before);
});

it('writes no signature for a record that has no vector yet', function (): void {
    store()->upsert([new VectorRecord('c', 'a', 'waiting', null, 'test:space')]);

    expect(DB::table('memory_vectors')->where('record_id', 'a')->value('signature'))->toBeNull();
});

it('keeps collections apart', function (): void {
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'mine')]);
    $store->upsert([new VectorRecord('other', 'a', 'theirs', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space')]);

    $matches = $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space'));

    expect($matches)->toHaveCount(1)->and($matches[0]->content)->toBe('mine');
});

/*
|--------------------------------------------------------------------------
| What a search must not return
|--------------------------------------------------------------------------
*/

it('never serves an expired memory, whether or not anything has pruned it', function (): void {
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([
        record('live', 'still here', expires: Carbon::now()->addHour()),
        record('dead', 'past its window', expires: Carbon::now()->subSecond()),
    ]);

    $matches = $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space'));

    // The row is still in the table. Expiry is enforced on read precisely so
    // that a pruner falling behind cannot resurrect it.
    expect($store->count('c'))->toBe(2)
        ->and($matches)->toHaveCount(1)
        ->and($matches[0]->recordId)->toBe('live');
});

it('never serves an unembedded memory', function (): void {
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'embedded'), new VectorRecord('c', 'b', 'waiting', null, 'test:space')]);

    $matches = $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space'));

    expect($store->count('c'))->toBe(2)
        ->and($store->count('c', embeddedOnly: true))->toBe(1)
        ->and($matches)->toHaveCount(1)
        ->and($matches[0]->recordId)->toBe('a');
});

it('filters on metadata', function (): void {
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([
        record('a', 'from the user', metadata: ['role' => 'user']),
        record('b', 'from the assistant', metadata: ['role' => 'assistant']),
    ]);

    $matches = $store->search(new VectorQuery(
        collection: 'c',
        vector: Vector::of([1.0, 0.0, 0.0, 0.0]),
        space: 'test:space',
        filter: ['role' => 'user'],
    ));

    expect($matches)->toHaveCount(1)->and($matches[0]->recordId)->toBe('a');
});

it('applies a minimum similarity before anything else sees the result', function (): void {
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([
        record('near', 'close', Vector::of([1.0, 0.0, 0.0, 0.0])),
        record('far', 'opposite', Vector::of([-1.0, 0.0, 0.0, 0.0])),
    ]);

    $matches = $store->search(new VectorQuery(
        collection: 'c',
        vector: Vector::of([1.0, 0.0, 0.0, 0.0]),
        space: 'test:space',
        minSimilarity: 0.5,
    ));

    expect($matches)->toHaveCount(1)->and($matches[0]->recordId)->toBe('near');
});

/*
|--------------------------------------------------------------------------
| Every guard, made to fail
|--------------------------------------------------------------------------
*/

it('refuses to blame an empty result on having nothing when the model changed', function (): void {
    // Swapping text-embedding-3-small for -large is a one-line config edit that
    // looks like an upgrade. Without this, the symptom is recall getting WORSE —
    // not empty, not broken — and being blamed on the embeddings.
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('a', 'stored under one model')]);

    expect(fn (): array => $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:other-model')))
        ->toThrow(EmbeddingSpaceMismatch::class, 'were embedded with [test:space]');
});

it('reports a genuinely empty collection as empty rather than as a mismatch', function (): void {
    // The other half of the guard above: it must not fire when there is simply
    // nothing there, or every fresh install would throw on its first recall.
    expect(store(IndexSettings::STRATEGY_EXACT)->search(
        new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space')
    ))->toBe([]);
});

it('names the record when a stored width contradicts its own space label', function (): void {
    $store = store(IndexSettings::STRATEGY_EXACT);

    $store->upsert([record('narrow', 'two wide', Vector::of([1.0, 1.0]))]);

    expect(fn (): array => $store->search(new VectorQuery('c', Vector::of([1.0, 0.0, 0.0, 0.0]), 'test:space')))
        ->toThrow(EmbeddingSpaceMismatch::class, '[narrow]');
});

it('refuses a memory with nothing in it', function (): void {
    // An empty memory would occupy a row, consume a candidate slot on every
    // search, and never be returned.
    expect(fn (): VectorRecord => record('a', '   '))
        ->toThrow(UnstorableMemory::class, 'Refusing to store the empty memory');
});

it('refuses nested metadata rather than letting it change JSON type with its contents', function (): void {
    expect(fn (): VectorRecord => record('a', 'hello', metadata: ['nested' => ['a' => 1]]))
        ->toThrow(UnstorableMemory::class, 'restricted to scalars');
});

it('refuses an object in metadata, which is the harness defect exactly', function (): void {
    expect(fn (): VectorRecord => record('a', 'hello', metadata: ['thing' => new stdClass]))
        ->toThrow(UnstorableMemory::class, 'stdClass');
});

/*
|--------------------------------------------------------------------------
| Removal
|--------------------------------------------------------------------------
*/

it('removes a record when asked, and reports how many went', function (): void {
    $store = store();

    $store->upsert([record('a', 'goodbye'), record('b', 'staying')]);

    expect($store->forget('c', ['a']))->toBe(1)
        ->and($store->count('c'))->toBe(1)
        ->and(DB::table('memory_vectors')->where('record_id', 'a')->count())->toBe(0);
});

it('purges a whole collection and reports how many went', function (): void {
    // The count is the point. "Delete this person's memories" is an assertion
    // someone may later have to evidence, and a void return is not evidence.
    $store = store();

    $store->upsert([record('a', 'one'), record('b', 'two')]);

    expect($store->purge('c'))->toBe(2)->and($store->count('c'))->toBe(0);
});

it('purges only what is older than a cut-off', function (): void {
    $store = store();

    $store->upsert([
        record('old', 'last year', at: Carbon::now()->subYear()),
        record('new', 'today', at: Carbon::now()),
    ]);

    expect($store->purge('c', Carbon::now()->subMonth()))->toBe(1)
        ->and($store->count('c'))->toBe(1);
});

it('reclaims expired rows when asked, without that being what enforces expiry', function (): void {
    $store = store();

    $store->upsert([
        record('live', 'here', expires: Carbon::now()->addHour()),
        record('dead', 'gone', expires: Carbon::now()->subSecond()),
    ]);

    expect($store->purgeExpired())->toBe(1)
        ->and($store->count('c'))->toBe(1);
});

it('lists what is written but not yet embedded, oldest first', function (): void {
    $store = store();

    $store->upsert([
        new VectorRecord('c', 'a', 'first', null, 'test:space'),
        new VectorRecord('c', 'b', 'second', null, 'test:space'),
        record('done', 'already embedded'),
    ]);

    $pending = $store->unembedded('c');

    expect($pending)->toHaveCount(2)
        ->and($pending[0]->id)->toBe('a')
        ->and($pending[1]->id)->toBe('b')
        ->and($pending[0]->isEmbedded())->toBeFalse();
});

it('is durable, which is the whole reason this driver exists', function (): void {
    expect(store()->durability())->toBe(Durability::Durable);
});
