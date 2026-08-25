<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use Illuminate\Support\Carbon;
use Prism\Memory\Enums\MemoryKind;

/**
 * One memory that came back, with its score broken into parts.
 *
 * The parts are kept separate on purpose. A single blended number tells you
 * that a memory ranked third and nothing about why — whether it was a close
 * semantic match that happened to be old, or a weak match that was written this
 * morning. Those call for opposite fixes, and collapsing them into one float
 * makes the difference unrecoverable.
 */
final readonly class Recalled
{
    /**
     * @param  float  $similarity  Cosine against the query. Untouched by weighting.
     * @param  float  $recency  Decay factor in [0, 1] at the time of the recall.
     * @param  float  $score  What the ordering actually used.
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $id,
        public string $content,
        public MemoryKind $kind,
        public ?string $role,
        public float $similarity,
        public float $recency,
        public float $score,
        public Carbon $occurredAt,
        public array $metadata = [],
    ) {}

    /**
     * The memory as one line of context.
     *
     * Deliberately fixed rather than configurable. This string is what a
     * provider's prompt cache hashes, so a format that varies between calls —
     * a localised date, a relative "3 days ago" — would change the prefix on
     * every request and invalidate the cache without anything appearing to have
     * changed. ISO-8601 in UTC is stable by construction.
     */
    public function asLine(): string
    {
        $role = $this->role === null ? $this->kind->value : $this->role;

        return sprintf('[%s] %s: %s', $this->occurredAt->utc()->toIso8601ZuluString(), $role, $this->content);
    }
}
