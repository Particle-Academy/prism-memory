<?php

declare(strict_types=1);

namespace Prism\Memory\Exceptions;

use RuntimeException;

/**
 * Thrown when a search would compare vectors from two different models.
 *
 * This is the failure that made the guard worth building. Changing
 * `text-embedding-3-small` to `-large` in a config file is a one-line edit that
 * looks like an upgrade. Every memory written before it lives in a 1536-
 * dimensional space and every one written after lives in a 3072-dimensional
 * one, and the two have nothing to do with each other — the axes do not
 * correspond, so a similarity computed across them is a number in [-1, 1] that
 * means nothing.
 *
 * Without this, the symptom is that recall gets worse. Not empty, not broken:
 * WORSE. It would be blamed on the embeddings, on the chunking, on the model —
 * on anything except a config change that appeared to be an improvement.
 *
 * So the store filters on the embedding space, and when that filter is what
 * emptied the result, it says so.
 */
final class EmbeddingSpaceMismatch extends RuntimeException
{
    /**
     * @param  list<string>  $collections
     */
    public static function collection(array $collections, string $stored, string $queried): self
    {
        $named = implode('], [', $collections);

        return new self(
            "The memories in [{$named}] were embedded with [{$stored}], and this search used "
            ."[{$queried}]. Vectors from two embedding models are not comparable — the dimensions do not "
            ."correspond, so any similarity between them is a number without a meaning.\n\n"
            .'Either put the previous model back, or re-embed the collection under the new one. Both are '
            .'deliberate acts; what this refuses to do is return nothing and let it look like there was '
            .'nothing to find.'
        );
    }

    public static function dimensions(string $recordId, int $stored, int $queried): self
    {
        return new self(
            "The stored memory [{$recordId}] is {$stored}-dimensional but the search vector is {$queried}-"
            .'dimensional, even though both claim the same embedding space. The space recorded against '
            .'that row does not describe what is in it — most likely a model whose output width changed '
            .'under a name that did not.'
        );
    }
}
