<?php

declare(strict_types=1);

namespace Prism\Memory\Enums;

/**
 * What a stored record IS.
 *
 * `Summary` and `Fact` are still absent, and still deliberately. The central
 * design question — is a memory the text that was said, a summary of it, or a
 * fact extracted from it? — is not answered by adding cases for the candidates,
 * and doing so would settle it by accident.
 *
 * Both cases here are text that was PRODUCED rather than derived: nobody paid a
 * model to write either one, so there is no second place for a model to be
 * wrong, and both are auditable against the thread they came from. That is the
 * substrate all three candidate answers share.
 *
 * ## Why `ToolResult` exists now, when it did not before
 *
 * It was withheld on a stated reason rather than an oversight: a tool result is
 * "structured data whose useful form is probably not the raw payload", and
 * storing it badly is worse than not storing it. The fear was being forced to
 * choose a lossy shape AT WRITE TIME.
 *
 * That fear was answered from two independent directions.
 *
 * A consumer measured their corpus: tool traffic is 93% of the transcript, and
 * of 375 stored tool results **371 are structured JSON and none are prose** —
 * so a memory layer storing observations and not tool results is optimising the
 * remaining 7%.
 *
 * And Mastra, which the harness was modelled on, turns out not to disagree with
 * that at all. Its `ToolCallFilter` reads like a policy of dropping tool traffic
 * and is documented as transient: "they affect only what's sent to the model on
 * that call. Stored messages, memory, and UI history keep their original tool
 * calls and results." It stores them and filters on the way out.
 *
 * So the write-time choice was never forced. **Store the payload as it came,
 * shape it on recall** — a stored payload can always be summarised on the way
 * out, and a stored summary can never be un-summarised.
 */
enum MemoryKind: string
{
    /**
     * Something that was said, stored as said.
     *
     * No model wrote it, so there is no second place for the model to be wrong,
     * and the record is auditable against the thread it came from.
     */
    case Observation = 'observation';

    /**
     * What a tool returned, stored verbatim.
     *
     * Verbatim is the point. This is the recovery layer that makes provider-side
     * context clearing safe: `clear_tool_uses` deletes tool results from the
     * model's view and hands back nothing, so without a store that kept them the
     * detail is simply gone.
     *
     * The SYMPTOM of that is workload-dependent and the two measurements we have
     * disagree about it. On short support conversations the agent redoes what it
     * cannot see and turns roughly double. On a long audit sweep turns went DOWN
     * four-fold and the agent refused to finish, because it could not stand
     * behind counts whose evidence had been cleared — having already asserted one
     * from a cleared result and caught itself. Repeating work costs money;
     * reporting a number you can no longer support is worse.
     *
     * What generalises is the cause, not the magnitude.
     *
     * A result that is an ERROR is still a tool result and is stored as one. The
     * consumer above found four such rows in their corpus, from a tool throwing
     * against a column a migration had not created yet. A store that assumes
     * payloads are well-formed would reject exactly the rows that explain what
     * went wrong.
     */
    case ToolResult = 'tool_result';
}
