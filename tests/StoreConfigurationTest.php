<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Exceptions\UnsafeMemoryConfiguration;
use Prism\Memory\PrismMemory;
use Prism\Memory\Stores\DatabaseVectorStore;
use Prism\Memory\Stores\VectorStoreManager;
use Prism\Memory\Support\BinarySignature;
use Prism\Memory\Support\IndexSettings;
use Tests\Fixtures\Owner;
use Tests\Fixtures\VolatileVectorStore;

function manager(array $config): VectorStoreManager
{
    return new VectorStoreManager(app(), array_replace_recursive(config('memory'), $config));
}

/*
|--------------------------------------------------------------------------
| The configuration a consumer actually gets
|--------------------------------------------------------------------------
|
| `prism-harness` shipped a default that broke a fresh install — its ephemeral
| store pointed at Redis, so `session()`, `key()` and `thread()` all worked and
| then the first `usingMode()` threw a raw connection error on any machine
| without one.
|
| Its suite could not have caught it: every case set the stores explicitly, so
| none of them exercised what an installing application receives. This file
| reads the SHIPPED config on purpose.
|
*/

it('works on a fresh install with nothing configured and no infrastructure', function (): void {
    // No Redis, no vector database, no queue worker, no published config. If
    // this test needs any setup step to pass, the package needs one too.
    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']))->synchronously();

    $memory->remember('My billing address is 4 Elm Row.');

    expect($memory->recall('billing address'))->toHaveCount(1);
});

it('ships pointing at the database, which every Laravel app already has', function (): void {
    expect(config('memory.store'))->toBe('database')
        ->and(app(VectorStoreManager::class)->store())->toBeInstanceOf(DatabaseVectorStore::class);
});

it('binds the contract to the configured store so prism-rag gets the same one', function (): void {
    // Two vector abstractions in one ecosystem is what makes an ecosystem a
    // collection. Two instances of one abstraction is the same mistake in a
    // smaller hat: the application would have configured a vector database and
    // half its embeddings would still be going somewhere else.
    expect(app(VectorStore::class))->toBe(app(VectorStoreManager::class)->store());
});

/*
|--------------------------------------------------------------------------
| Every guard, made to fail
|--------------------------------------------------------------------------
*/

it('refuses a volatile store for memory that is meant to survive', function (): void {
    // The reason this is loud: losing a memory has no symptom. Nothing errors,
    // nothing degrades to a default — the agent simply stops knowing something
    // it was told, and behaves exactly as it would have if it never had been.
    $manager = manager([])->extend('cache', fn (): VectorStore => new VolatileVectorStore);

    expect(fn (): VectorStore => manager(['store' => 'cache'])
        ->extend('cache', fn (): VectorStore => new VolatileVectorStore)
        ->store())
        ->toThrow(UnsafeMemoryConfiguration::class, 'reports itself as volatile');

    // And the guard does not fire on the durable store beside it.
    expect($manager->store())->toBeInstanceOf(DatabaseVectorStore::class);
});

it('names both ways out rather than only saying no', function (): void {
    // An exception that refuses without saying what to do instead is a
    // roadblock. This one has to name the durable alternative AND the opt-out,
    // because there are two legitimate intentions behind the same config.
    $error = UnsafeMemoryConfiguration::volatileStore('cache')->getMessage();

    expect($error)->toContain('database')->toContain('require_durable');
});

it('allows a volatile store when the application says it meant to', function (): void {
    // Turning the guard off is how an application records that a disposable
    // working set was the intention.
    $store = manager(['store' => 'cache', 'require_durable' => false])
        ->extend('cache', fn (): VectorStore => new VolatileVectorStore)
        ->store();

    expect($store)->toBeInstanceOf(VolatileVectorStore::class);
});

it('refuses a store name that is not configured', function (): void {
    expect(fn (): VectorStore => manager(['store' => 'pinecone'])->store())
        ->toThrow(UnsafeMemoryConfiguration::class, 'which is not configured');
});

it('refuses an index strategy it does not have', function (): void {
    expect(fn (): IndexSettings => new IndexSettings(strategy: 'hnsw'))
        ->toThrow(InvalidArgumentException::class, 'Unknown vector index strategy');
});

it('refuses a signature width that cannot be packed into whole bytes', function (): void {
    // Signatures are byte strings, so a width that is not a multiple of 8 would
    // silently drop its remainder — a narrower index than the configuration
    // says, ranking slightly worse for a reason nothing reports.
    expect(fn (): BinarySignature => new BinarySignature('seed', 100))
        ->toThrow(InvalidArgumentException::class, 'multiple of 8');
});

it('refuses to compare signatures of different widths', function (): void {
    // Which is what a live `bits` change without a re-index would produce.
    expect(fn (): int => BinarySignature::distance('ab', 'abcd'))
        ->toThrow(InvalidArgumentException::class, 'different widths');
});

it('lets an application register a real vector database', function (): void {
    // The extension point exists because a vector database needs a client this
    // package cannot construct — and because prism-rag resolves the same store
    // through the same manager, so it is one registration for both.
    $registered = new DatabaseVectorStore(
        connection: DB::connection(),
        index: new IndexSettings(strategy: IndexSettings::STRATEGY_EXACT),
    );

    $store = manager(['store' => 'pgvector'])
        ->extend('pgvector', fn (): VectorStore => $registered)
        ->store();

    expect($store)->toBe($registered);
});
