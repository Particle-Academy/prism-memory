<?php

declare(strict_types=1);

namespace Prism\Memory\Contracts;

use Prism\Memory\ValueObjects\Vector;

/**
 * Turns text into vectors.
 *
 * A contract rather than a direct Prism call for one reason: it is the seam
 * where a test can be honest. The default implementation goes through
 * `Prism::embeddings()`, because a package that embeds its own way is a second
 * provider integration nobody asked for.
 */
interface Embedder
{
    /**
     * Embed several inputs in ONE provider call.
     *
     * Batch, not singular, and that is the whole point of the signature. Every
     * provider that offers embeddings takes an array, and a turn producing six
     * memories should cost one round trip rather than six. An implementation
     * that loops internally is free to exist and is a performance bug.
     *
     * The returned list MUST be the same length as $inputs and in the same
     * order. An implementation that returns fewer is not returning a subset —
     * it is misaligning every vector after the gap with someone else's text,
     * which stores silently and retrieves the wrong memory forever.
     *
     * @param  list<string>  $inputs
     * @return list<Vector>
     */
    public function embed(array $inputs): array;

    /**
     * Which embedding space these vectors live in.
     *
     * Stored alongside every vector and checked at search time. Two vectors
     * from different models are not comparable, and the failure when they are
     * compared anyway is not an error — it is a plausible-looking similarity
     * score computed across two unrelated coordinate systems.
     *
     * Includes the dimension count, because a provider is free to change the
     * width of a model's output and an application would otherwise find out by
     * getting worse answers.
     */
    public function space(): string;
}
