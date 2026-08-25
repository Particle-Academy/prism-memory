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
        public string $recordId,
        public string $content,
        public array $metadata,
        public float $similarity,
        public Carbon $occurredAt,
    ) {}
}
