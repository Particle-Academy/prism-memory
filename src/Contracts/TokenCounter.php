<?php

declare(strict_types=1);

namespace Prism\Memory\Contracts;

/**
 * Estimates how much context a piece of text will cost.
 *
 * A contract because the default is an ESTIMATE and the package says so out
 * loud. Bundling a real tokeniser would mean bundling one per model family and
 * keeping them current, which is a second package's job; guessing silently
 * would mean a caller who asked for a 2000-token budget getting 2600.
 *
 * Bind your own implementation to this interface when the budget has to be
 * exact.
 */
interface TokenCounter
{
    public function count(string $text): int;
}
