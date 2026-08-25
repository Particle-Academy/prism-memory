<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use DateTimeInterface;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Enums\Durability;
use Prism\Memory\ValueObjects\VectorQuery;

/**
 * A store that reports itself volatile, and does nothing else.
 *
 * It exists so the durability guard can be made to FAIL. Every method below is
 * unreachable — resolving this store throws before any of them can be called —
 * and that is the assertion: if a test ever gets a value out of one of them,
 * the guard did not fire.
 *
 * A check nobody has watched fail is a hypothesis. This is what turns the
 * durability refusal into a check.
 */
final class VolatileVectorStore implements VectorStore
{
    #[\Override]
    public function upsert(iterable $records): void {}

    #[\Override]
    public function search(VectorQuery $query): array
    {
        return [];
    }

    #[\Override]
    public function forget(string $collection, array $recordIds): int
    {
        return 0;
    }

    #[\Override]
    public function purge(string $collection): int
    {
        return 0;
    }

    #[\Override]
    public function purgeOccurredBefore(string $collection, DateTimeInterface $before): int
    {
        return 0;
    }

    #[\Override]
    public function count(string $collection, bool $embeddedOnly = false): int
    {
        return 0;
    }

    #[\Override]
    public function unembedded(string $collection, int $limit = 100): array
    {
        return [];
    }

    #[\Override]
    public function durability(): Durability
    {
        return Durability::Volatile;
    }
}
