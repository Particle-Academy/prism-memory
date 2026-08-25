<?php

declare(strict_types=1);

namespace Prism\Memory\ValueObjects;

use Prism\Memory\Contracts\VectorStore;

/**
 * Where a stored record came from, in keys both packages agree on.
 *
 * This exists as CODE rather than as a paragraph in the contract's docs, and
 * that is the whole point of it. `docs/patterns/README.md` is blunt about why:
 * restated documentation drifts exactly like restated code, and nothing tests
 * prose. Two packages that each read a convention and each implement it are two
 * packages that will differ in a month — one writing `source_id` and the other
 * `document_id`, both correct by their own reading, and an application that
 * installs both getting two provenance vocabularies in one table.
 *
 * So there is one implementation, in the package that owns the store contract,
 * and `prism-rag` calls it instead of agreeing with it.
 *
 * ## The reserved prefix
 *
 * **`source_*` is reserved for the ecosystem.** An application is free to put
 * anything else in a record's metadata; keys under this prefix belong to the
 * packages, so a new provenance field can be added here without renegotiating
 * with every consumer. `kind` and `role` are reserved on the same basis — they
 * were in use before this convention was written down and are grandfathered
 * rather than renamed, because renaming them would orphan every row already
 * stored.
 *
 * ## Absent and null are ONE state here — a port MUST NOT distinguish them
 *
 * Every other round trip in this package is careful to keep them apart. It is
 * the first divergence axis in decision 0007, PHP has one absent value where
 * JavaScript has two and Python has another, and there is a test for it.
 *
 * Provenance is the exception, on purpose rather than by oversight. The test
 * that justifies it is not "does this follow the default" but "do the two
 * states carry different meaning": there is no useful difference between "no
 * page number was recorded" and "the page number is null". They are defined as
 * identical, so collapsing them loses nothing — while writing nine explicit
 * nulls onto every memory that has no provenance would put nine dead keys in
 * every row and nine dead branches in every metadata filter, bought for
 * nothing.
 *
 * Unset fields are therefore OMITTED by `toMetadata()`, and `fromMetadata()`
 * maps absent back to null. The round trip is lossless BECAUSE THE TWO STATES
 * WERE NEVER DISTINCT — that sentence is the whole justification, and a port
 * that reinvents the distinction breaks it.
 *
 * **This is stated here and in {@see VectorStore} rather than only here.** A
 * port meets a language with two absent values and will reinvent the
 * distinction unless the contract tells it not to, and by then the exception
 * has survived exactly as long as nobody ported the package.
 *
 * ## The layer this collapse does NOT apply to
 *
 * It is an interpretation rule, not a storage rule. The STORE must round-trip
 * an explicit null under a `source_*` key faithfully, exactly as it does for
 * any other key — dropping it on write because "provenance treats them as the
 * same" is the wrong layer, and it is a divergence a suite that only checks
 * absent-vs-null on an ordinary key will not catch.
 */
final readonly class Provenance
{
    public const PREFIX = 'source_';

    public function __construct(
        /** Stable identity of the thing this record came from — a document, a thread, a ticket. */
        public ?string $id = null,
        /** Where it can be fetched or linked, for a citation a reader can follow. */
        public ?string $uri = null,
        /** Human label for a citation. */
        public ?string $title = null,
        /**
         * Which revision of the source this record was derived from.
         *
         * The field that makes re-ingestion decidable. Without it, "has this
         * document changed since we last chunked it" can only be answered by
         * re-chunking it and comparing, which is the work it was meant to avoid.
         */
        public ?string $version = null,
        /** Ordinal position within the source — the chunk index. */
        public ?int $part = null,
        public ?int $page = null,
        /** Character span within the source, for highlighting a citation in place. */
        public ?int $offset = null,
        public ?int $length = null,
        /**
         * The heading path, joined rather than nested.
         *
         * Metadata is scalars-only, so `['Billing', 'Refunds']` arrives here as
         * `'Billing > Refunds'`. Joining is the cost of a metadata surface that
         * can be filtered with JSON path expressions and that does not change
         * JSON type when it empties — see `UnstorableMemory::nonScalarMetadata`.
         */
        public ?string $heading = null,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function toMetadata(): array
    {
        return array_filter([
            self::PREFIX.'id' => $this->id,
            self::PREFIX.'uri' => $this->uri,
            self::PREFIX.'title' => $this->title,
            self::PREFIX.'version' => $this->version,
            self::PREFIX.'part' => $this->part,
            self::PREFIX.'page' => $this->page,
            self::PREFIX.'offset' => $this->offset,
            self::PREFIX.'length' => $this->length,
            self::PREFIX.'heading' => $this->heading,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        $string = static function (string $key) use ($metadata): ?string {
            $value = $metadata[self::PREFIX.$key] ?? null;

            return is_scalar($value) ? (string) $value : null;
        };

        $integer = static function (string $key) use ($metadata): ?int {
            $value = $metadata[self::PREFIX.$key] ?? null;

            return is_numeric($value) ? (int) $value : null;
        };

        return new self(
            id: $string('id'),
            uri: $string('uri'),
            title: $string('title'),
            version: $string('version'),
            part: $integer('part'),
            page: $integer('page'),
            offset: $integer('offset'),
            length: $integer('length'),
            heading: $string('heading'),
        );
    }

    /**
     * Whether anything at all is recorded.
     *
     * A record with no provenance is ordinary — most memories are something a
     * person said, with nothing to cite — so this is a question callers ask
     * rather than a failure.
     */
    public function isEmpty(): bool
    {
        return $this->toMetadata() === [];
    }
}
