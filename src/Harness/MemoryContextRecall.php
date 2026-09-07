<?php

declare(strict_types=1);

namespace Prism\Memory\Harness;

use Prism\Harness\Contracts\ContextRecall;
use Prism\Memory\PrismMemory;

/**
 * Reaching back into memory for detail that left the context window.
 *
 * The other half of {@see MemoryEvictionSink}, and the reason the pair is worth
 * having over a table and a `LIKE`: this searches by MEANING. An agent asking
 * "what was the reference for the disputed charge" finds the turn that carried
 * it without having guessed the words it was written in.
 *
 * ## The budget is honoured, not hoped for
 *
 * `prism-memory`'s `recall()` already fills to an estimated token ceiling
 * rather than returning a fixed number of passages that may not fit, so the
 * harness's budget maps straight onto it. That is not incidental — recall
 * exists because the window is finite, and handing back everything relevant
 * would re-expand exactly what compaction just removed.
 *
 * ## Chronological, not score-ordered
 *
 * A `Recollection` comes back in time order and `asContext()` renders it that
 * way. Score order is unstable: two memories a thousandth of a cosine apart
 * swap places when a third arrives, which changes the prompt prefix and misses
 * the provider's cache — and the bill goes UP with nothing saying why.
 *
 * ## Scope
 *
 * The harness passes the thread key, which is what {@see MemoryEvictionSink}
 * was given. **Both sides must agree on it.** They once did not — eviction
 * wrote into one namespace and recall searched another, silently, because a
 * lookup that matches nothing is indistinguishable from a conversation with
 * nothing to find.
 */
final readonly class MemoryContextRecall implements ContextRecall
{
    public function __construct(
        private PrismMemory $memory,
        private mixed $owner,
        /**
         * Restrict recall to what compaction pushed out.
         *
         * Off by default, so a recall reaches everything the conversation
         * remembers rather than only the part that fell out of the window —
         * which is what an agent asking "what was I told earlier" means. Turn
         * it on when deliberate memories and evicted turns should not mix.
         */
        private bool $evictedOnly = false,
    ) {}

    #[\Override]
    public function recall(string $query, string $scope, int $budget): string
    {
        $recollection = $this->memory->for($this->owner, $scope)->recall(
            $query,
            budget: $budget,
            filter: $this->evictedOnly ? ['evicted' => true] : [],
        );

        // Empty is a legitimate answer and the tool reports it as "nothing
        // found" rather than as an error. Returning a placeholder here would
        // put words in the model's context that nobody said.
        return $recollection->isEmpty() ? '' : $recollection->asContext();
    }
}
