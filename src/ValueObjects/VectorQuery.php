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
     * The metadata equality filter, narrowed after validation.
     *
     * The parameter is deliberately WIDE for the same reason `$collection` is:
     * a docblock is a statement of intent any caller can ignore, and a guard
     * against something the type system already promised is a guard nobody can
     * make fail. Filter KEYS reach the query as a JSON path, so the guard on
     * them has to be reachable.
     *
     * @var array<string, scalar|null|list<scalar>>
     */
    public array $filter;

    /**
     * @param  string|array<array-key, mixed>  $collection  One namespace, or several searched together.
     * @param  array<array-key, mixed>  $filter  Equality on metadata keys; a list means "any of".
     * @param  float|null  $minSimilarity  Applied by the store, before any reranking.
     */
    public function __construct(
        string|array $collection,
        public Vector $vector,
        public string $space,
        public int $limit = 64,
        array $filter = [],
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

        // Filter keys reach the query as a JSON PATH, not as a bound value, and
        // an unusable one fails SILENTLY. `metadata->a->b` compiles to
        // `$."a"."b"` — a traversal into nesting that metadata does not have by
        // contract, so it matches zero rows and reports nothing. The caller
        // reads that as "no relevant memories" rather than "your filter was
        // malformed", which is the worse of the two answers because it looks
        // like a result.
        //
        // A `"` is the loud half of the same problem: it produces a malformed
        // path and the DATABASE raises, pointing whoever reads the stack at the
        // wrong layer entirely.
        //
        // Not injection — the value is bound and the key lands inside a
        // single-quoted string literal, so a quote breaks the JSON path and not
        // the statement. Refused here anyway, because "cannot escape the query"
        // and "does what the caller asked" are different claims.
        foreach (array_keys($filter) as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException(
                    'A metadata filter key must be a non-empty string, and a ['.get_debug_type($key).'] '
                    .'was given. Anything else reaches the JSON path as whatever it casts to, which is a '
                    .'filter on a key nobody named.'
                );
            }

            if (str_contains($key, '->') || str_contains($key, '"')) {
                throw new InvalidArgumentException(
                    'A metadata filter key may not contain "->" or a double quote, and ['.$key.'] does. '
                    .'Metadata is flat and scalar by contract, so a traversal key can never match — it '
                    .'would return zero rows with no error, which reads as "nothing relevant" instead of '
                    .'"this filter is wrong".'
                );
            }
        }

        /** @var array<string, scalar|null|list<scalar>> $filter */
        $this->filter = $filter;
    }
}
