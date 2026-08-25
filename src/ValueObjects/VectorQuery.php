<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use InvalidArgumentException;

/**
 * A search against one collection of one embedding space.
 *
 * `limit` is what the STORE returns, not what the caller sees. Ranking that
 * weighs anything other than raw similarity — recency, in this package — has to
 * rescore, and rescoring the top 8 by similarity can only ever reorder those 8.
 * So the memory layer over-fetches and the store's limit is the candidate
 * budget rather than the answer size.
 */
final readonly class VectorQuery
{
    /**
     * @param  array<string, scalar|null|list<scalar>>  $filter  Equality on metadata keys; a list means "any of".
     * @param  float|null  $minSimilarity  Applied by the store, before any reranking.
     */
    public function __construct(
        public string $collection,
        public Vector $vector,
        public string $space,
        public int $limit = 64,
        public array $filter = [],
        public ?float $minSimilarity = null,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('A vector query limit must be at least 1.');
        }

        if ($minSimilarity !== null && ($minSimilarity < -1.0 || $minSimilarity > 1.0)) {
            throw new InvalidArgumentException('A minimum cosine similarity must be within [-1, 1].');
        }
    }
}
