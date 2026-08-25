<?php

declare(strict_types=1);

use Prism\Memory\PrismMemory;
use Prism\Memory\ValueObjects\Provenance;
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
