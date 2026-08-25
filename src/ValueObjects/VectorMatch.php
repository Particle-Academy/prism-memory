<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use Illuminate\Support\Carbon;

/**
 * One hit, with the score that produced it.
 *
 * The vector itself is deliberately absent. Nothing downstream of a search
 * needs it, and carrying it would mean holding several megabytes of doubles in
 * memory for a result set the caller is about to render as text.
 *
 * `collection` is present because a query may name SEVERAL. Without it a
 * multi-collection search returns an unattributable merge — a passage from the
 * handbook and one from the contract, indistinguishable — and a caller trying
 * to cite them would have to search again per collection to find out which was
 * which, which is the round trip the amendment removed.
 *
 * It is populated for single-collection searches too. A field that is only
 * sometimes filled in is a field every caller has to null-check.
 */
final readonly class VectorMatch
{
    /**
     * @param  array<string, scalar|null>  $metadata
     * @param  float  $similarity  Cosine, in [-1, 1]. Never blended with anything else —
     *                             a store reports likeness and nothing more, so a caller
     *                             weighing recency has an unmixed number to weigh.
     */
    public function __construct(
        public string $collection,
        public string $recordId,
        public string $content,
        public array $metadata,
        public float $similarity,
        public Carbon $occurredAt,
    ) {}
}
