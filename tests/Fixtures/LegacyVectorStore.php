<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use DateTimeInterface;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Enums\Durability;
use Prism\Memory\ValueObjects\VectorQuery;

/** A custom store implementing only the original eight required methods. */
final class LegacyVectorStore implements VectorStore
{
    public function __construct(private readonly VectorStore $inner) {}

    public function upsert(iterable $records): void
    {
        $this->inner->upsert($records);
    }

    public function search(VectorQuery $query): array
    {
        return $this->inner->search($query);
    }

    public function forget(string $collection, array $recordIds): int
    {
        return $this->inner->forget($collection, $recordIds);
    }

    public function purge(string $collection): int
    {
        return $this->inner->purge($collection);
    }

    public function purgeOccurredBefore(string $collection, DateTimeInterface $before): int
    {
        return $this->inner->purgeOccurredBefore($collection, $before);
    }

    public function count(string $collection, bool $embeddedOnly = false): int
    {
        return $this->inner->count($collection, $embeddedOnly);
    }

    public function unembedded(string $collection, int $limit = 100): array
    {
        return $this->inner->unembedded($collection, $limit);
    }

    public function durability(): Durability
    {
        return $this->inner->durability();
    }
}
