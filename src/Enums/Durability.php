<?php

declare(strict_types=1);

namespace Prism\Memory\Enums;

/**
 * Whether a store's contents survive a deploy.
 *
 * Deliberately a copy of `Prism\Harness\Enums\Durability` rather than a shared
 * import. Decision 0008 rules out a common parent package: it would make every
 * satellite wait on a release of the parent, and the coupling costs more than
 * the duplication. Two enums with the same two cases and the same meaning is
 * the recorded, intended shape — the duplication is the point, not an accident.
 *
 * Memory earns the distinction on its own terms. A recalled memory that a
 * `cache:clear` can remove does not degrade to a default; it degrades to an
 * agent that has forgotten a fact the user told it, and behaves confidently
 * anyway. That is a correctness failure wearing a cache miss's clothes, so a
 * store has to say which of the two it is instead of the package guessing.
 */
enum Durability: string
{
    /**
     * Contents may vanish at any time — a flush, an eviction, a deploy.
     *
     * Only safe for memory whose loss is genuinely acceptable: a working set
     * scoped to a single run, where the alternative to remembering is asking
     * again rather than being wrong.
     */
    case Volatile = 'volatile';

    /**
     * Contents survive until deliberately removed.
     *
     * Required for anything a user was told would be remembered.
     */
    case Durable = 'durable';

    public function isDurable(): bool
    {
        return $this === self::Durable;
    }
}
