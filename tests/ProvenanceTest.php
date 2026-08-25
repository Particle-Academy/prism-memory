<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Memory\PrismMemory;
use Prism\Memory\Stores\DatabaseVectorStore;
use Prism\Memory\Support\IndexSettings;
use Prism\Memory\ValueObjects\Provenance;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;
use Tests\Fixtures\Owner;

/*
|--------------------------------------------------------------------------
| The provenance convention
|--------------------------------------------------------------------------
|
| Ratified as a required convention rather than a first-class field on
| VectorMatch: a field that is always null for memory is worse than a
| documented key. It exists as CODE so `prism-rag` calls it instead of agreeing
| with it — restated documentation drifts exactly like restated code, and
| nothing tests prose.
|
*/

it('writes every provenance key under the reserved prefix', function (): void {
    // `source_*` is reserved for the ecosystem, so a new provenance field can be
    // added without renegotiating with every consumer, and an application knows
    // which keys are not its own.
    $metadata = (new Provenance(
        id: 'handbook.md',
        uri: 'https://example.test/handbook.md',
        title: 'Employee Handbook',
        version: 'a1b2c3',
        part: 4,
        page: 12,
        offset: 900,
        length: 512,
        heading: 'Billing > Refunds',
    ))->toMetadata();

    expect(array_keys($metadata))->toBe([
        'source_id',
        'source_uri',
        'source_title',
        'source_version',
        'source_part',
        'source_page',
        'source_offset',
        'source_length',
        'source_heading',
    ]);

    foreach (array_keys($metadata) as $key) {
        expect($key)->toStartWith(Provenance::PREFIX);
    }
});

it('round-trips through metadata unchanged', function (): void {
    $provenance = new Provenance(
        id: 'handbook.md',
        uri: 'https://example.test/handbook.md',
        title: 'Employee Handbook',
        version: 'a1b2c3',
        part: 4,
        page: 12,
        offset: 900,
        length: 512,
        heading: 'Billing > Refunds',
    );

    expect(Provenance::fromMetadata($provenance->toMetadata()))->toEqual($provenance);
});

it('omits what it does not know rather than writing nine dead nulls', function (): void {
    // The one place this package deliberately collapses absent and null. There
    // is no useful difference between "no page number was recorded" and "the
    // page number is null", and nine explicit nulls on every memory with no
    // provenance is nine dead keys in every row and nine dead branches in every
    // metadata filter.
    expect((new Provenance(id: 'thread-9'))->toMetadata())->toBe(['source_id' => 'thread-9'])
        ->and((new Provenance)->toMetadata())->toBe([])
        ->and((new Provenance)->isEmpty())->toBeTrue();
});

it('rebuilds absent keys as null, so the collapse is lossless', function (): void {
    $rebuilt = Provenance::fromMetadata(['source_id' => 'thread-9']);

    expect($rebuilt->id)->toBe('thread-9')
        ->and($rebuilt->page)->toBeNull()
        ->and($rebuilt)->toEqual(new Provenance(id: 'thread-9'));
});

it('survives a store round trip on a real memory', function (): void {
    // The convention is only worth anything if it comes back off the wire. This
    // is the same absent-vs-null axis the rest of the suite guards, applied to
    // the keys prism-rag will read.
    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']))->synchronously();

    $memory->remember(
        'The refund window is 30 days.',
        metadata: (new Provenance(id: 'handbook.md', part: 4, heading: 'Billing > Refunds'))->toMetadata(),
    );

    $recalled = $memory->recall('refund window')->all()[0];
    $provenance = Provenance::fromMetadata($recalled->metadata);

    expect($provenance->id)->toBe('handbook.md')
        ->and($provenance->part)->toBe(4)
        ->and($provenance->heading)->toBe('Billing > Refunds')
        ->and($provenance->page)->toBeNull();
});

it('can be filtered on, which is why metadata is scalars-only', function (): void {
    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']))->synchronously();

    $memory->remember('The refund window is 30 days.', metadata: (new Provenance(id: 'handbook.md'))->toMetadata());
    $memory->remember('The refund window is 14 days.', metadata: (new Provenance(id: 'contract.md'))->toMetadata());

    $recalled = $memory->recall('refund window', filter: ['source_id' => 'contract.md']);

    expect($recalled)->toHaveCount(1)
        ->and($recalled->all()[0]->content)->toContain('14 days');
});

it('keeps an integer an integer across the round trip', function (): void {
    // A store that stringified metadata would return "4" here, and every
    // filter written against a page or a part would silently stop matching.
    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']))->synchronously();

    $memory->remember('a passage', metadata: (new Provenance(part: 4, page: 12))->toMetadata());

    $metadata = $memory->recall('a passage')->all()[0]->metadata;

    expect($metadata['source_part'])->toBe(4)->and($metadata['source_page'])->toBe(12);
});

/*
|--------------------------------------------------------------------------
| Two rules, two layers
|--------------------------------------------------------------------------
|
| The collapse is an INTERPRETATION rule. The store keeps absent and null apart
| for every key without exception, including the reserved ones. A port that
| applies the collapse at the storage layer — dropping null `source_*` keys on
| write — produces a store that passes its own tests and disagrees with this
| one, and the existing absent-vs-null test cannot catch it because that test
| uses an ordinary key.
|
| These are the discriminating cases.
|
*/

it('stores an explicit null under a reserved key without normalising it away', function (): void {
    // The case that proves the rule. `distinguishes a metadata key that is null
    // from one that is absent` uses an ORDINARY key, so it stays green against
    // a store that special-cases `source_*` — which is exactly the mistake the
    // contract now warns a port against.
    $store = new DatabaseVectorStore(DB::connection(), new IndexSettings(strategy: IndexSettings::STRATEGY_EXACT));
    $vector = Vector::of([1.0, 0.0]);

    $store->upsert([new VectorRecord('c', 'a', 'x', $vector, 's', ['source_page' => null])]);

    $metadata = $store->search(new VectorQuery('c', $vector, 's'))[0]->metadata;

    expect($metadata)->toHaveKey('source_page')
        ->and($metadata['source_page'])->toBeNull();
});

it('leaves a reserved key absent when it was never written', function (): void {
    // The other half: the store must not INVENT the key either.
    $store = new DatabaseVectorStore(DB::connection(), new IndexSettings(strategy: IndexSettings::STRATEGY_EXACT));
    $vector = Vector::of([1.0, 0.0]);

    $store->upsert([new VectorRecord('c', 'a', 'x', $vector, 's', ['other' => 1])]);

    expect($store->search(new VectorQuery('c', $vector, 's'))[0]->metadata)->not->toHaveKey('source_page');
});

it('reads a stored explicit null and a stored absence as the same provenance', function (): void {
    // And here the two layers meet: storage kept them apart, interpretation
    // treats them as one, and both are correct. A port that distinguished them
    // in Provenance would fail this; a port that collapsed them in the store
    // would fail the two above.
    $store = new DatabaseVectorStore(DB::connection(), new IndexSettings(strategy: IndexSettings::STRATEGY_EXACT));
    $vector = Vector::of([1.0, 0.0]);

    $store->upsert([
        new VectorRecord('c', 'explicit', 'x', $vector, 's', ['source_id' => 'doc', 'source_page' => null]),
        new VectorRecord('c', 'absent', 'y', $vector, 's', ['source_id' => 'doc']),
    ]);

    $provenances = [];

    foreach ($store->search(new VectorQuery('c', $vector, 's')) as $match) {
        $provenances[$match->recordId] = Provenance::fromMetadata($match->metadata);
    }

    expect($provenances['explicit'])->toEqual($provenances['absent'])
        ->and($provenances['explicit']->page)->toBeNull();
});

it('treats an explicit null and an absent key as one state in both directions', function (): void {
    // Stated as the pair it is, so neither direction can drift alone.
    expect(Provenance::fromMetadata(['source_page' => null]))->toEqual(Provenance::fromMetadata([]))
        ->and((new Provenance(page: null))->toMetadata())->toBe((new Provenance)->toMetadata());
});

it('never emits a null value, so the collapse cannot leak back into storage', function (): void {
    $metadata = (new Provenance(id: 'doc', page: null, offset: null))->toMetadata();

    expect($metadata)->toBe(['source_id' => 'doc'])
        ->and(array_filter($metadata, static fn (mixed $value): bool => $value === null))->toBe([]);
});
