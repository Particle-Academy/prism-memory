<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use Prism\Memory\Contracts\TokenCounter;

/**
 * A rough token count, and it says so.
 *
 * Four characters to a token is the widely-quoted approximation for English
 * prose through a byte-pair encoder. It is wrong for code, wrong for JSON,
 * wrong for languages that do not use spaces, and wrong by a different amount
 * for every model family — which is why this is a default behind a contract
 * rather than a number the package presents as fact.
 *
 * The README records that budget-aware recall has NOT been validated against a
 * real tokeniser, because a green test suite next to an unstated approximation
 * reads as a guarantee, and this is not one. Bind your own {@see TokenCounter}
 * when the budget has to hold exactly.
 *
 * Multibyte-aware on purpose: `strlen` counts bytes, so a paragraph of Japanese
 * would be estimated at three times its length and a caller's budget would fill
 * after two memories. `mb_strlen` at least counts the same units the
 * approximation was derived from.
 */
final readonly class HeuristicTokenCounter implements TokenCounter
{
    public function __construct(
        private float $charactersPerToken = 4.0,
        /**
         * Fixed cost per counted string.
         *
         * Every memory arrives in the prompt with its own delimiters, timestamp
         * and role label. Ignoring that undercounts by a few tokens per item,
         * which is invisible for one memory and is a whole memory's worth of
         * overspend across twenty.
         */
        private int $overheadTokens = 4,
    ) {}

    #[\Override]
    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / $this->charactersPerToken) + $this->overheadTokens;
    }
}
