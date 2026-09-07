<?php

declare(strict_types=1);

namespace Prism\Memory\Harness;

use Prism\Harness\Contracts\EvictionSink;
use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\PrismMemory;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * What leaves an agent's context window, written into memory.
 *
 * The half of `prism-harness`'s compaction contract that this package is the
 * natural home for. The harness decides WHEN a turn leaves the window and hands
 * it over; this decides where it goes, and the answer is the same store the
 * application already searches by meaning.
 *
 * ## Why this lives here rather than in the harness
 *
 * `prism-harness` deliberately does not depend on `prism-memory` — it ships the
 * contract and a sink that discards, because it will not decide that somebody's
 * conversation is disposable. The dependency runs the other way and only in
 * `require-dev`: memory knows how to store things, and an adapter belongs with
 * the store rather than with the thing being stored.
 *
 * **The harness is not required at runtime.** If it is absent this class is
 * simply never referenced — nothing in `prism-memory` resolves it, and the
 * service provider does not bind it. An application that has both wires it in
 * one line; an application with only memory never loads the file.
 *
 * ## What it stores, and why the shape matters
 *
 * Verbatim, through `remember()`, in a collection scoped to the conversation.
 * Tool results included — on an agentic transcript they are the majority, one
 * consumer measured 93%, and a sink that kept only the prose would be keeping
 * the half of the conversation least likely to hold the answer.
 *
 * Verbatim because a stored payload can always be summarised on the way out and
 * a stored summary can never be un-summarised. That is the same principle that
 * decided {@see MemoryKind::ToolResult}, and it matters more here: this sink is
 * what a summarising compaction strategy leans on when the summary drops the
 * detail somebody later needs.
 */
final readonly class MemoryEvictionSink implements EvictionSink
{
    public function __construct(
        private PrismMemory $memory,
        /**
         * The owner every evicted turn is filed under.
         *
         * Memory is addressed by owner AND scope. The harness gives a scope —
         * the thread — but has no opinion about who owns it, so the application
         * supplies that. Usually the participant whose conversation it is.
         */
        private mixed $owner,
    ) {}

    #[\Override]
    public function store(array $messages, string $scope): void
    {
        $memory = $this->memory->for($this->owner, $scope);

        foreach ($messages as $message) {
            // Handed over ONE AT A TIME rather than as a batch.
            //
            // `remember()` refuses a message type it cannot store, and a batch
            // would take the whole eviction down with it — the harness catches
            // that and carries on, so the visible result would be an entire
            // turn silently unstored because one part of it was unusual.
            //
            // A sink is custody. Losing all of it because one message was odd
            // is the failure this contract exists to prevent.
            try {
                $memory->remember([$message], $this->metadataFor($message));
            } catch (\Throwable $failure) {
                report($failure);
            }
        }
    }

    /**
     * Marks a memory as having left a window rather than having been recorded
     * deliberately.
     *
     * Worth distinguishing on recall: an application may reasonably want to
     * search only what it chose to remember, or only what compaction pushed
     * out, and without this they are the same rows.
     *
     * @return array<string, scalar|null>
     */
    private function metadataFor(Message $message): array
    {
        return [
            'evicted' => true,
            'message_type' => match (true) {
                $message instanceof UserMessage => 'user',
                $message instanceof AssistantMessage => 'assistant',
                $message instanceof ToolResultMessage => 'tool_result',
                default => 'other',
            },
        ];
    }
}
