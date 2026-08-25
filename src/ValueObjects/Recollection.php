<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Traversable;

/**
 * What recall returned, in a form that can become context.
 *
 * ## The ordering, and why it is not by score
 *
 * `all()` and everything built on it are CHRONOLOGICAL. `ranked()` is the one
 * that is ordered by score, and it exists for inspection rather than for
 * building a prompt.
 *
 * That is a prompt-caching decision, and it is the difference between a memory
 * layer that saves money and one that costs money while appearing to save it.
 * Anthropic and OpenAI both price a cached prefix at a fraction of a fresh one,
 * and both cache a PREFIX — a byte-identical run from the start of the request.
 * Recalled memories go near the front, so they are inside that prefix.
 *
 * Score order is unstable by nature. Two memories separated by 0.001 of cosine
 * swap places when a third memory is added, when the query is reworded, when a
 * recency weight ticks over. The SET can be identical and the ORDER different,
 * which produces a different prefix, which misses the cache — so a memory layer
 * ordering by score turns every turn into a full-price write, and the usage
 * numbers say the request got more expensive without saying why.
 *
 * Chronological order does not have that property: a memory's position depends
 * on when it happened, which never changes. Adding a new memory appends rather
 * than reshuffles, which is the one mutation a prefix cache survives.
 *
 * Ties break on record id, because `occurred_at` genuinely ties — a tool loop
 * writes several memories inside the same second — and an unstable tiebreak
 * would put the churn straight back.
 *
 * {@see digest()} makes the whole thing checkable: same digest, same prefix.
 *
 * ## How to hand this to Prism
 *
 * ```php
 * Prism::text()
 *     ->withSystemPrompt($instructions)          // static, cache this
 *     ->withSystemPrompt($recollection->asContext())
 *     ->withPrompt($question)
 *     ->asText();
 * ```
 *
 * NOT `withMessages($recollection->asMessages())->withPrompt($question)`.
 * Prism refuses a request that carries both an explicit message list and a
 * prompt — deliberately, because there is no defensible order to merge them in
 * — so that combination throws as soon as recall returns anything. `asMessages()`
 * is for callers assembling a whole message list themselves.
 *
 * @implements IteratorAggregate<int, Recalled>
 */
final readonly class Recollection implements Countable, IteratorAggregate
{
    /**
     * @param  list<Recalled>  $memories  Already in chronological order.
     */
    private function __construct(
        private array $memories,
        public int $estimatedTokens,
    ) {}

    /**
     * @param  list<Recalled>  $memories  In any order.
     */
    public static function of(array $memories, int $estimatedTokens = 0): self
    {
        usort(
            $memories,
            fn (Recalled $a, Recalled $b): int => [$a->occurredAt->getTimestamp(), $a->id]
                <=> [$b->occurredAt->getTimestamp(), $b->id],
        );

        return new self($memories, $estimatedTokens);
    }

    public static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * Chronological, oldest first.
     *
     * @return list<Recalled>
     */
    public function all(): array
    {
        return $this->memories;
    }

    /**
     * Best first. For looking at, not for building a prompt — see the class
     * docblock for why the two are different questions.
     *
     * @return list<Recalled>
     */
    public function ranked(): array
    {
        $ranked = $this->memories;

        usort($ranked, fn (Recalled $a, Recalled $b): int => $b->score <=> $a->score);

        return $ranked;
    }

    /**
     * The memories as one block of text.
     */
    public function asContext(): string
    {
        if ($this->memories === []) {
            return '';
        }

        return implode("\n", array_map(fn (Recalled $memory): string => $memory->asLine(), $this->memories));
    }

    /**
     * The memories as one system message.
     *
     * ONE message, not one per memory. Replaying recollections as if they were
     * prior user and assistant turns tells the model a fragment was the last
     * thing said, which is a claim about the conversation that is not true —
     * and it invites the model to answer the recalled question instead of the
     * one being asked. A recollection is context. It is labelled as context.
     */
    public function asSystemMessage(): SystemMessage
    {
        return new SystemMessage($this->asContext());
    }

    /**
     * @return array<int, Message>
     */
    public function asMessages(): array
    {
        return $this->memories === [] ? [] : [$this->asSystemMessage()];
    }

    /**
     * A fingerprint of the exact bytes this recollection contributes.
     *
     * Two recalls with the same digest produce the same prefix and therefore the
     * same cache behaviour. Log it, or assert on it, and a change in provider
     * cache-read tokens stops being a mystery: either the digest moved and the
     * miss is explained, or it did not and the cause is somewhere else.
     */
    public function digest(): string
    {
        return hash('sha256', $this->asContext());
    }

    public function isEmpty(): bool
    {
        return $this->memories === [];
    }

    #[\Override]
    public function count(): int
    {
        return count($this->memories);
    }

    /**
     * @return Traversable<int, Recalled>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->memories);
    }
}
