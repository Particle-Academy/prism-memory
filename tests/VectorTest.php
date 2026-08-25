<?php

declare(strict_types=1);

use Prism\Memory\Exceptions\InvalidVector;
use Prism\Memory\ValueObjects\Vector;
use Prism\Prism\ValueObjects\Embedding;

/*
|--------------------------------------------------------------------------
| The round trip
|--------------------------------------------------------------------------
|
| `prism-harness` shipped a defect that stored correctly, loaded correctly, and
| produced an array where a value object belonged — which then exploded one
| provider call later. Forty-five green tests missed it, because every one of
| them asserted that something came back rather than that the SAME thing came
| back.
|
| So these assert on identity, with `===`, on values chosen to break a lossy
| implementation rather than a broken one.
|
*/

it('round-trips floats through packing without changing a single bit', function (): void {
    $values = [
        0.1,                        // not representable in binary; the classic
        -0.1,
        1 / 3,
        PHP_FLOAT_EPSILON,
        PHP_FLOAT_MIN,
        1.0e-300,                   // float32 flushes this to zero
        1.0e150,                    // float32 overflows to INF well below here
        -1.0e150,
        123456789.123456789,        // more digits than float32 can hold
        0.30000000000000004,        // the canonical 0.1 + 0.2
    ];

    $vector = Vector::of($values);

    // Strict, not a delta. A tolerance here would pass a float32 store, which
    // is exactly the implementation this is meant to rule out.
    expect(Vector::unpack($vector->pack())->toArray())->toBe($values);
});

it('keeps the sign of negative zero, which a value comparison cannot see', function (): void {
    // -0.0 === 0.0 is TRUE in PHP, so the assertion above would pass an
    // implementation that silently dropped the sign bit. The only way to see it
    // is to look at the bytes.
    // Carries a real component too, because a vector of nothing but zeroes has
    // no direction and is refused before it can be packed at all.
    $packed = Vector::of([-0.0, 0.0, 1.0])->pack();

    expect(base64_decode($packed, true))->toBe(pack('e', -0.0).pack('e', 0.0).pack('e', 1.0));
});

it('packs little-endian regardless of the machine it runs on', function (): void {
    // `pack('d')` is machine byte order. A row written on one architecture and
    // read on another would come back byte-reversed, and a database is the one
    // component that routinely outlives the machine that wrote to it.
    expect(base64_decode(Vector::of([1.0])->pack(), true))->toBe("\x00\x00\x00\x00\x00\x00\xf0\x3f");
});

it('normalises the numeric strings Prism permits inside an embedding', function (): void {
    // Prism types Embedding::$embedding as array<int, int|string|float>, so a
    // provider handing back numeric strings is inside its contract.
    $vector = Vector::fromEmbedding(new Embedding(['0.5', 2, 1.5]));

    expect($vector->toArray())->toBe([0.5, 2.0, 1.5]);
});

it('takes one square root rather than two, which is worth a whole bit of accuracy', function (): void {
    // The obvious form — dot / (sqrt(a) * sqrt(b)) — returns
    // 0.99999999999999978 for this vector against itself. That is BELOW 1.0, so
    // clamping cannot rescue it: a caller filtering on `>= 1.0` would find
    // nothing identical to itself and never see the clamp.
    $vector = Vector::of([0.1, 0.2, 0.3, 0.4]);

    expect($vector->cosine($vector))->toBe(1.0);
});

it('guarantees the range but not the last bit', function (): void {
    // Recorded rather than asserted away. Cosine is floating point arithmetic,
    // and some vector somewhere will score 0.9999999999999999 against itself.
    // What IS guaranteed is that nothing ever escapes [-1, 1] — that is what the
    // clamp is for, and it is the property a caller can build on.
    $vectors = [
        Vector::of([1 / 3, 2 / 3, 1 / 7, 22 / 7]),
        Vector::of([1e-8, 2e-8, 3e30, 1.0]),
        Vector::of([-5.5, 0.0, 5.5, -5.5]),
        Vector::of([PHP_FLOAT_EPSILON, 1.0, -1.0, PHP_FLOAT_MIN]),
    ];

    foreach ($vectors as $left) {
        foreach ($vectors as $right) {
            expect($left->cosine($right))->toBeGreaterThanOrEqual(-1.0)->toBeLessThanOrEqual(1.0);
        }
    }
});

it('does not collapse to zero when the product of the squared lengths overflows', function (): void {
    // Each vector is scorable on its own — the squared components are finite —
    // but sumOfSquares * sumOfSquares is INF. Without the fallback the one-root
    // form divides by INF and returns 0.0: a plausible-looking "not similar at
    // all" for a vector compared with itself.
    $huge = Vector::of([1e100, 2e100, 3e100]);

    expect($huge->cosine($huge))->toBeGreaterThan(0.999)->toBeLessThanOrEqual(1.0);
});

it('refuses a vector so large it cannot be scored, and says that rather than "zero"', function (): void {
    // A separate failure from a degenerate vector, with a separate message,
    // because it has a separate cause. Told it was "zero magnitude", whoever
    // reads this goes looking in exactly the wrong place.
    expect(fn (): Vector => Vector::of([1e200, 2e200, 3e200]))
        ->toThrow(InvalidVector::class, 'overflows a double when squared');
});

it('scores opposite vectors as -1.0 and orthogonal ones as 0.0', function (): void {
    expect(Vector::of([1.0, 0.0])->cosine(Vector::of([-1.0, 0.0])))->toBe(-1.0)
        ->and(Vector::of([1.0, 0.0])->cosine(Vector::of([0.0, 1.0])))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Every guard, made to fail
|--------------------------------------------------------------------------
|
| A check nobody has watched fail is a hypothesis.
|
*/

it('refuses a NAN component rather than storing a memory that can never match', function (): void {
    // NAN comparisons are always false, so a record carrying one would score
    // NAN against everything and silently stop being retrievable — no error,
    // ever, at any point.
    expect(fn (): Vector => Vector::of([1.0, NAN, 3.0]))
        ->toThrow(InvalidVector::class, 'NAN or INF');
});

it('refuses an INF component', function (): void {
    expect(fn (): Vector => Vector::of([INF]))->toThrow(InvalidVector::class, 'NAN or INF');
});

it('refuses a non-numeric string component', function (): void {
    expect(fn (): Vector => Vector::of([1.0, 'not-a-number']))
        ->toThrow(InvalidVector::class, 'non-numeric string');
});

it('refuses an empty embedding', function (): void {
    expect(fn (): Vector => Vector::of([]))->toThrow(InvalidVector::class, 'at least one dimension');
});

it('refuses to score a zero vector instead of returning a plausible 0.0', function (): void {
    // A zero vector has no direction. Returning 0.0 would be a number that
    // looks like an answer.
    expect(fn (): float => Vector::of([0.0, 0.0])->magnitude())
        ->toThrow(InvalidVector::class, 'zero magnitude');
});

it('refuses to compare vectors of different widths', function (): void {
    expect(fn (): float => Vector::of([1.0, 2.0])->cosine(Vector::of([1.0, 2.0, 3.0])))
        ->toThrow(InvalidVector::class, 'Cannot compare a 2-dimensional vector with a 3-dimensional one');
});

it('refuses a stored payload that is not a whole number of doubles', function (): void {
    expect(fn (): Vector => Vector::unpack(base64_encode('12345')))
        ->toThrow(InvalidVector::class, 'not base64 of a whole number');
});

it('surfaces corrupted bytes when the number is first used, not silently', function (): void {
    // unpack() skips per-component validation on purpose — it is the read path.
    // The lazy magnitude check is what keeps that from being a hole: bytes that
    // decode to NAN still fail, just at the point the value is needed.
    $corrupt = base64_encode(str_repeat("\xff", 8));

    expect(fn (): float => Vector::unpack($corrupt)->magnitude())->toThrow(InvalidVector::class);
});
