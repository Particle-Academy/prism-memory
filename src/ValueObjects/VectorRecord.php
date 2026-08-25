<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use Illuminate\Support\Carbon;
use Prism\Memory\Exceptions\UnstorableMemory;

/**
 * One addressable thing in a vector store.
 *
 * Part of the ecosystem's vector-store contract, which lives in this package
 * because memory needed it first — `prism-rag` consumes this rather than
 * defining a second one. So the shape has to serve both: a remembered turn and
 * an ingested document chunk are the same three things — an id, some text, and
 * a vector — differing only in what the metadata says.
 *
 * The id is the caller's, not the store's, and that is what makes writing
 * idempotent. `prism-memory` derives it from a digest of the content, so
 * remembering the same sentence twice writes one row. `prism-rag` needs the
 * same property for re-ingestion and gets it from the same mechanism.
 */
final readonly class VectorRecord
{
    /**
     * Everything a store is guaranteed to be holding.
     *
     * @var array<string, scalar|null>
     */
    public array $metadata;

    /**
     * The narrowing boundary.
     *
     * `$metadata` arrives as `mixed` and leaves as `scalar|null`, and that
     * asymmetry is the whole reason the check below is real rather than
     * decorative. PHP does not enforce a docblock, so a parameter annotated
     * `array<string, scalar|null>` is a statement of intent that any caller can
     * ignore — and a guard against something the type system already promised
     * is a guard nobody can make fail, which makes it a hypothesis rather than
     * a check.
     *
     * @param  string  $collection  The namespace a search is scoped to.
     * @param  string  $id  Caller-owned identity. Upsert replaces by (collection, id).
     * @param  Vector|null  $vector  Null while the record is written but not yet embedded.
     * @param  string  $space  Which embedding space $vector belongs to. Vectors from two
     *                         different models are not comparable, and a store that mixes
     *                         them silently returns nonsense rather than nothing.
     * @param  array<string, mixed>  $metadata  Validated here; scalar or null after.
     */
    public function __construct(
        public string $collection,
        public string $id,
        public string $content,
        public ?Vector $vector,
        public string $space,
        array $metadata = [],
        public ?Carbon $occurredAt = null,
        public ?Carbon $expiresAt = null,
    ) {
        if (trim($content) === '') {
            throw UnstorableMemory::blankContent($id);
        }

        $narrowed = [];

        foreach ($metadata as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw UnstorableMemory::nonScalarMetadata($key, get_debug_type($value));
            }

            $narrowed[(string) $key] = $value;
        }

        $this->metadata = $narrowed;
    }

    public function isEmbedded(): bool
    {
        return $this->vector instanceof Vector;
    }

    public function withVector(Vector $vector): self
    {
        return new self(
            collection: $this->collection,
            id: $this->id,
            content: $this->content,
            vector: $vector,
            space: $this->space,
            metadata: $this->metadata,
            occurredAt: $this->occurredAt,
            expiresAt: $this->expiresAt,
        );
    }
}
