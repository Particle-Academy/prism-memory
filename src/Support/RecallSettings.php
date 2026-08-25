<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use InvalidArgumentException;
use Prism\Memory\ValueObjects\Weighting;

/**
 * The defaults a recall uses when the caller does not say.
 *
 * `overfetch` is the one worth explaining. Ranking that considers anything
 * beyond raw similarity has to rescore, and rescoring the top 8 by similarity
 * can only ever reorder those 8 — a memory that is the seventieth most similar
 * and was written an hour ago cannot win a recency-weighted ranking it was
 * never entered into. So the store is asked for `limit x overfetch` candidates
 * and the weighting picks from those.
 *
 * Bigger is more faithful and more expensive, linearly, in the hot path. Eight
 * is chosen so a default recall of 8 memories considers 64 — enough for recency
 * weighting to matter, small enough to stay well inside the LSH candidate
 * budget.
 */
final readonly class RecallSettings
{
    public function __construct(
        public int $limit = 8,
        public int $overfetch = 8,
        public ?float $minScore = null,
        public Weighting $weighting = new Weighting,
        /**
         * How long an identical query's embedding is reused.
         *
         * The single biggest lever on recall latency, because embedding the
         * query is a network round trip that sits in front of every turn — tens
         * to hundreds of milliseconds, before the database has been touched.
         * Applications ask the same question repeatedly far more often than
         * seems likely: a fixed retrieval prompt, a re-render, a retried
         * request, a user rephrasing nothing.
         *
         * Zero disables it. It is on by default because Laravel's default cache
         * store is safe wherever it points — worst case an `array` driver makes
         * it a per-request memo, which still pays for itself inside one turn and
         * assumes no infrastructure the installing app never claimed to have.
         */
        public int $queryCacheSeconds = 300,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('A recall limit must be at least 1.');
        }

        if ($overfetch < 1) {
            throw new InvalidArgumentException(
                'Overfetch must be at least 1. Below that the store would be asked for fewer candidates '
                .'than the caller wants back.'
            );
        }

        if ($queryCacheSeconds < 0) {
            throw new InvalidArgumentException('A query cache lifetime cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            limit: (int) ($config['limit'] ?? 8),
            overfetch: (int) ($config['overfetch'] ?? 8),
            minScore: isset($config['min_score']) && is_numeric($config['min_score'])
                ? (float) $config['min_score']
                : null,
            weighting: new Weighting(
                relevance: (float) ($config['relevance'] ?? 1.0),
                recency: (float) ($config['recency'] ?? 0.0),
                halfLifeSeconds: (int) ($config['half_life'] ?? Weighting::DEFAULT_HALF_LIFE),
            ),
            queryCacheSeconds: (int) ($config['query_cache'] ?? 300),
        );
    }
}
