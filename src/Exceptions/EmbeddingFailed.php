<?php

declare(strict_types=1);

namespace Prism\Memory\Exceptions;

use RuntimeException;

/**
 * Thrown when an embedding response cannot be trusted to line up with its input.
 *
 * Batching is what makes embedding affordable, and it is also what makes this
 * class necessary: in a batch, a vector is bound to its text by POSITION and
 * by nothing else. There is no id to check it against. So a response of the
 * wrong length is not a partial success — it is every subsequent vector
 * attached to somebody else's sentence.
 *
 * Nothing downstream can detect that. The rows store cleanly, the searches run,
 * and the memory returned for "what is my address" is whatever text happened to
 * sit one position along. Losing a turn's memories is recoverable; corrupting
 * the store is not.
 */
final class EmbeddingFailed extends RuntimeException
{
    public static function countMismatch(int $expected, int $received, string $model): self
    {
        return new self(
            "Embedding [{$model}] returned {$received} vectors for {$expected} inputs. Vectors are matched "
            .'to their text by position, so a short response does not drop one memory — it attaches every '
            ."later vector to the wrong text, silently and permanently.\n\n"
            .'The whole batch is refused. Nothing was stored.'
        );
    }
}
