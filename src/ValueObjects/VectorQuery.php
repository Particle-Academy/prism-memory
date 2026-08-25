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
     * The collections this query searches, normalised to a list.
     *
     * The narrowing boundary, for the same reason `VectorRecord` has one: PHP
     * does not enforce a docblock, so `list<string>` on a parameter is a
     * statement of intent that any caller can ignore — and a guard against
     * something the type system already promised is a guard nobody can make
     * fail. The parameter therefore accepts any array and this property is what
     * every driver relies on.
     *
     * @var list<string>
     */
    public array $collections;

    /**
     * @param  string|array<array-key, mixed>  $collection  One namespace, or several searched together.
     * @param  array<string, scalar|null|list<scalar>>  $filter  Equality on metadata keys; a list means "any of".
     * @param  float|null  $minSimilarity  Applied by the store, before any reranking.
     */
    public function __construct(
        string|array $collection,
        public Vector $vector,
        public string $space,
        public int $limit = 64,
        public array $filter = [],
        public ?float $minSimilarity = null,
    ) {
        $collections = is_string($collection) ? [$collection] : array_values($collection);

        // An empty string and an empty array are the same mistake — nothing was
        // named — so they get the same message. Letting `''` fall through to the
        // per-element check would diagnose it as a type problem and send whoever
        // reads it looking at the wrong thing.
        if ($collections === [] || $collections === ['']) {
            throw new InvalidArgumentException(
                'A vector query must name at least one collection. An unscoped search would read every '
                .'collection in the table, which across owners is one participant being handed another '
                ."participant's memories."
            );
        }

        foreach ($collections as $name) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException(
                    'A collection name must be a non-empty string, and a ['.get_debug_type($name).'] was '
                    .'given. Anything else reaches the WHERE clause as whatever it casts to, which is a '
                    .'search of a collection nobody named.'
                );
            }
        }

        $this->collections = $collections;

        if ($limit < 1) {
            throw new InvalidArgumentException('A vector query limit must be at least 1.');
        }

        if ($minSimilarity !== null && ($minSimilarity < -1.0 || $minSimilarity > 1.0)) {
            throw new InvalidArgumentException('A minimum cosine similarity must be within [-1, 1].');
        }
    }
}
