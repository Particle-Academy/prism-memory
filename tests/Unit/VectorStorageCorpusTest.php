<?php

declare(strict_types=1);

use Prism\Memory\Exceptions\InvalidVector;
use Prism\Memory\ValueObjects\Vector;

/**
 * The cross-language vector-storage corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not drifted
 * from the code it was generated against — which is what makes the ports'
 * "I match the reference" assertions mean anything. Without it they would be
 * pinned to a snapshot of PHP that PHP had moved on from, and every one of them
 * would stay green while the claim quietly stopped being true.
 */
function vectorStorageCorpus(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../fixtures/memory-vector-storage.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];
}

function storageFor(array $case): array
{
    try {
        $vector = Vector::of($case['values']);

        return [
            'refused' => false,
            'packed' => $vector->pack(),
            'round_trips' => Vector::unpack($vector->pack())->values === $vector->values,
        ];
    } catch (InvalidVector) {
        return ['refused' => true, 'packed' => null, 'round_trips' => null];
    }
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(vectorStorageCorpus())->toHaveCount(9);
});

it('still stores exactly what the corpus recorded', function (): void {
    foreach (vectorStorageCorpus() as $case) {
        expect(storageFor($case))->toBe($case['storage']['php'], $case['id'].' — '.$case['title']);
    }
});

it('still refuses on the WRITE path, which is the behaviour the ports were fixed to match', function (): void {
    // G-22. If this ever moved to the score path here, the ports would be left
    // matching a reference that no longer behaves that way, and the suite would
    // go green for the wrong reason in three repositories at once.
    expect(fn () => Vector::of([0.0, -0.0]))->toThrow(InvalidVector::class);
    expect(fn () => Vector::of([1e-300, 1e300]))->toThrow(InvalidVector::class);
});

it('agrees with both ports on every row', function (): void {
    foreach (vectorStorageCorpus() as $case) {
        expect($case['storage']['ts'])->toBe($case['storage']['php'], $case['id']);
        expect($case['storage']['py'])->toBe($case['storage']['php'], $case['id']);
    }
});
