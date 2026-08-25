<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use InvalidArgumentException;

/**
 * How much relevance and how much recency.
 *
 * Pure vector similarity retrieves the most SIMILAR memory, which is not the
 * same thing as the most useful one. "What is my billing address?" is most
 * similar to every previous time the address was discussed — including the one
 * from two years ago that has since been superseded. Similarity has no opinion
 * about which of two matching memories is still true.
 *
 * Recency has the opposite failure: it retrieves the newest thing, related or
 * not. Neither axis is right on its own, and which mix is right depends on what
 * the memory is for — a support history wants recency, a corpus of preferences
 * mostly does not. So the caller weights them, and the default is relevance
 * alone, because that is the behaviour someone expects from something called
 * semantic recall.
 *
 * Weights are normalised, so a score stays inside [-1, 1] whatever the mix.
 * That is what keeps `minScore` meaning the same thing when the mix changes;
 * without it, raising the recency weight would raise every score and quietly
 * disable the caller's threshold.
 */
final readonly class Weighting
{
    public const DEFAULT_HALF_LIFE = 7 * 24 * 60 * 60;

    public function __construct(
        public float $relevance = 1.0,
        public float $recency = 0.0,
        /**
         * How long it takes a memory's recency contribution to halve.
         *
         * Exponential rather than linear, and not a cut-off. A cut-off makes a
         * memory disappear the moment it crosses a boundary, which shows up as
         * an agent that knew something yesterday and does not today with
         * nothing having changed. Decay degrades instead of switching.
         */
        public int $halfLifeSeconds = self::DEFAULT_HALF_LIFE,
    ) {
        if ($relevance < 0.0 || $recency < 0.0) {
            throw new InvalidArgumentException('Recall weights cannot be negative: a negative weight ranks the worst matches first.');
        }

        if ($relevance + $recency <= 0.0) {
            throw new InvalidArgumentException(
                'Recall needs at least one non-zero weight. With both at zero every memory scores the same '
                .'and the order you get back is whatever the database felt like.'
            );
        }

        if ($halfLifeSeconds < 1) {
            throw new InvalidArgumentException('A recency half-life must be at least one second.');
        }
    }

    public static function relevanceOnly(): self
    {
        return new self(relevance: 1.0, recency: 0.0);
    }

    public static function balanced(): self
    {
        return new self(relevance: 0.7, recency: 0.3);
    }

    /**
     * How much a memory of this age still counts, in [0, 1].
     *
     * Ages in the future — a clock skew between two workers, or a record dated
     * deliberately ahead — clamp to 1.0 rather than scoring above it, so a
     * mis-set timestamp cannot outrank a genuine match.
     */
    public function decay(int $ageSeconds): float
    {
        if ($ageSeconds <= 0) {
            return 1.0;
        }

        return 2.0 ** (-$ageSeconds / $this->halfLifeSeconds);
    }

    public function score(float $similarity, int $ageSeconds): float
    {
        $total = $this->relevance + $this->recency;

        return (($similarity * $this->relevance) + ($this->decay($ageSeconds) * $this->recency)) / $total;
    }

    public function usesRecency(): bool
    {
        return $this->recency > 0.0;
    }
}
