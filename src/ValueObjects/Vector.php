<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use Prism\Memory\Exceptions\InvalidVector;
use Prism\Prism\ValueObjects\Embedding;

/**
 * An embedding, in a form that survives a database round trip unchanged.
 *
 * The storage format is base64 of `pack('e*')` — IEEE 754 doubles, explicitly
 * little-endian — and not JSON and not float32. Both of those alternatives are
 * smaller or more readable, and both change the numbers:
 *
 *  - float32 loses roughly nine significant digits per component, so a vector
 *    written and read back scores differently against the same query than it
 *    did at write time. Nothing errors; the ranking just drifts, by an amount
 *    no test that only checks "did it come back" would notice.
 *  - JSON depends on `serialize_precision`, which is an ini setting the host
 *    application owns. A package whose stored numbers change because someone
 *    tuned php.ini is not storing numbers, it is storing opinions about them.
 *
 * `'e'` rather than `'d'` matters for the same reason: `'d'` is machine byte
 * order, so a row written on one architecture and read on another would come
 * back byte-reversed. That is not a hypothetical for a database, which is the
 * one part of a system that routinely outlives the machine that wrote to it.
 *
 * The cost is size: 1536 dimensions is 12KB packed, 16KB base64. That is the
 * deliberate trade, and it is the reason the README says a real vector database
 * is the answer past a certain scale rather than pretending this one is.
 */
final class Vector
{
    /**
     * The SQUARED length, cached.
     *
     * Squared rather than the magnitude itself because that is the form
     * `cosine()` wants — see there for why one square root beats two.
     * Recomputing it per comparison would double the arithmetic in the hot path,
     * where one recall scores hundreds of candidates against one query.
     */
    private ?float $sumOfSquares = null;

    /**
     * @param  list<float>  $values
     */
    private function __construct(public readonly array $values, ?float $sumOfSquares = null)
    {
        $this->sumOfSquares = $sumOfSquares;
    }

    /**
     * Build from untrusted numbers — a provider response, a config file, a test.
     *
     * Validates every component. This is the write path and runs once per
     * record, so the O(n) pass is affordable and the guarantee is worth having:
     * a single NAN inside a stored vector makes every score computed against it
     * NAN, and NAN comparisons are false, so the record silently stops being
     * retrievable rather than failing.
     *
     * @param  array<int, int|float|string>  $values
     */
    public static function of(array $values): self
    {
        if ($values === []) {
            throw InvalidVector::empty();
        }

        $floats = [];
        $sumOfSquares = 0.0;

        foreach (array_values($values) as $index => $value) {
            if (is_string($value)) {
                if (! is_numeric($value)) {
                    throw InvalidVector::notNumeric($index, $value);
                }

                $value = (float) $value;
            }

            $float = (float) $value;

            if (! is_finite($float)) {
                throw InvalidVector::notFinite($index);
            }

            $floats[] = $float;
            $sumOfSquares += $float * $float;
        }

        // Accumulated on the way past rather than in a second loop, so the
        // write path pays for it once and every later comparison gets it free.
        return new self($floats, self::assertScorable($sumOfSquares));
    }

    /**
     * Build from Prism's own embedding value object.
     *
     * `Embedding::$embedding` is typed `array<int, int|string|float>`, so a
     * provider handing back numeric strings is inside its contract. Normalising
     * here rather than at every call site is the point: everything downstream
     * gets floats.
     */
    public static function fromEmbedding(Embedding $embedding): self
    {
        return self::of($embedding->embedding);
    }

    /**
     * Rebuild from what {@see pack()} wrote.
     *
     * Deliberately does NOT re-validate every component. The bytes came from
     * this class, and this is the read path — it runs once per candidate, and a
     * per-component pass over a few hundred candidates is real time spent on a
     * guarantee `of()` already made at write time.
     *
     * What it does check is length, and — lazily, in `magnitude()` — that the
     * vector is not degenerate. Corrupted bytes therefore still fail loudly,
     * just at the moment the number is first used rather than on the way in.
     */
    public static function unpack(string $packed): self
    {
        $binary = base64_decode($packed, true);

        if ($binary === false || $binary === '' || strlen($binary) % 8 !== 0) {
            throw InvalidVector::corruptPayload();
        }

        /** @var array<int, float>|false $unpacked */
        $unpacked = \unpack('e*', $binary);

        if ($unpacked === false) {
            throw InvalidVector::corruptPayload();
        }

        return new self(array_values($unpacked));
    }

    /**
     * @return non-empty-string
     */
    public function pack(): string
    {
        return base64_encode(\pack('e*', ...$this->values));
    }

    public function dimensions(): int
    {
        return count($this->values);
    }

    /**
     * Euclidean length.
     *
     * Throws on a degenerate vector rather than letting `cosine()` divide by
     * zero and return INF or NAN. A zero vector is not a similarity of zero —
     * it is a vector with no direction, and every answer about its similarity
     * to anything is meaningless. Returning 0.0 would be a plausible-looking
     * number, which is worse than a failure.
     */
    public function magnitude(): float
    {
        return sqrt($this->sumOfSquares());
    }

    /**
     * Cosine similarity, in [-1, 1].
     *
     * Dimension mismatch throws. Two vectors of different lengths are almost
     * always two different embedding models, and the only honest answer to
     * "how similar are these" is that the question does not apply.
     *
     * ## Why one square root and not two
     *
     * The obvious form is `dot / (sqrt(sumA) * sqrt(sumB))`, and it rounds three
     * times: twice taking roots, once multiplying. `dot / sqrt(sumA * sumB)` is
     * the same quantity with two roundings, and the difference is visible at the
     * end of the range that matters most — a vector compared with ITSELF.
     *
     * Measured, not assumed. With `[0.1, 0.2, 0.3, 0.4]` the three-rounding form
     * returns 0.99999999999999978 and the two-rounding form returns exactly 1.0.
     * The clamp below cannot rescue the first: it is BELOW 1.0, so a caller
     * filtering on `>= 1.0` finds nothing identical to itself and the clamp
     * never sees the value.
     *
     * This is an improvement and not a guarantee, and the README says so.
     * Exact equality against 1.0 is not a property to build on; duplicate
     * detection in this package is done by content digest, where it belongs.
     */
    public function cosine(self $other): float
    {
        if (count($this->values) !== count($other->values)) {
            throw InvalidVector::dimensionMismatch($this->dimensions(), $other->dimensions());
        }

        $dot = 0.0;
        $mine = $this->values;
        $theirs = $other->values;

        foreach ($mine as $index => $value) {
            $dot += $value * $theirs[$index];
        }

        $product = $this->sumOfSquares() * $other->sumOfSquares();

        $similarity = is_finite($product)
            ? $dot / sqrt($product)
            // Two vectors large enough that the product of their squared lengths
            // overflows a double. Rarer than it sounds and not impossible, and
            // the fallback is exact enough — it is only the last bit that is at
            // stake, and INF would cost every bit.
            : $dot / ($this->magnitude() * $other->magnitude());

        // Clamped so the RANGE is a guarantee even where the last bit is not.
        return max(-1.0, min(1.0, $similarity));
    }

    private function sumOfSquares(): float
    {
        if ($this->sumOfSquares !== null) {
            return $this->sumOfSquares;
        }

        $sum = 0.0;

        foreach ($this->values as $value) {
            $sum += $value * $value;
        }

        return $this->sumOfSquares = self::assertScorable($sum);
    }

    /**
     * Two different failures that a single "is it finite" check would conflate.
     *
     * A zero-length vector has no direction. A vector whose squared components
     * overflow a double has a direction that this arithmetic cannot reach —
     * every dot product involving it is INF, so a similarity would come back as
     * NAN or as a confident 0.0.
     *
     * They need separate messages because they need separate fixes, and a
     * message that says "zero magnitude" about a vector of 1e200s sends whoever
     * reads it looking in the wrong place.
     *
     * Neither occurs with real embeddings, whose components sit close to the
     * unit interval. Both are refused rather than worked around, because the
     * machinery to rescale for numerical stability would cost every ordinary
     * comparison something to rescue a case that cannot arise from a provider.
     */
    private static function assertScorable(float $sumOfSquares): float
    {
        if (! is_finite($sumOfSquares)) {
            throw InvalidVector::unscorable();
        }

        if ($sumOfSquares <= 0.0) {
            throw InvalidVector::degenerate();
        }

        return $sumOfSquares;
    }

    /**
     * @return list<float>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
