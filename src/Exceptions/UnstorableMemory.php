<?php

declare(strict_types=1);

namespace Prism\Memory\Exceptions;

use RuntimeException;

/**
 * Thrown when something cannot be stored in a form that comes back identical.
 *
 * `prism-harness` shipped a defect of exactly this shape: content that stored
 * without error, loaded without error, and rebuilt as an array where a value
 * object belonged — which then exploded one provider call later, inside a
 * mapper, naming a class the application had never mentioned.
 *
 * The lesson generalises past that one bug. Anything whose stored form is not
 * exactly its loaded form has to be refused at the boundary where the mistake
 * is, rather than accepted and discovered somewhere it cannot be traced back.
 */
final class UnstorableMemory extends RuntimeException
{
    public static function blankContent(string $id): self
    {
        return new self(
            "Refusing to store the empty memory [{$id}]. There is nothing to embed, so the record could "
            .'never be recalled — it would occupy a row, consume a candidate slot on every search, and '
            .'never be returned.'
        );
    }

    public static function unrememberable(string $type): self
    {
        return new self(
            "Cannot remember a [{$type}]. `remember()` takes Prism messages — most usefully "
            .'`$response->messages`, which is the whole exchange including tool steps — or plain strings. '
            .'Anything else is refused rather than skipped.

'
            .'Skipping would be worse: the call would succeed, nothing would be stored, and recall would '
            .'return nothing later with no error anywhere to connect the two.'
        );
    }

    public static function nonScalarMetadata(string|int $key, string $type): self
    {
        return new self(
            "Memory metadata [{$key}] is a [{$type}]. Metadata is restricted to scalars and null for two "
            ."reasons.\n\n"
            .'It is queried with JSON where-clauses, which only work on scalar leaves. And PHP cannot tell '
            .'an empty JSON object from an empty JSON array, so a nested map serialises as `[]` when empty '
            .'and `{}` when populated — the same field changing JSON type with its contents, which is '
            .'finding F-3 in the conformance corpus and would make these rows unreadable from the '
            .'TypeScript and Python ports. Flatten it, or put it in the content.'
        );
    }
}
