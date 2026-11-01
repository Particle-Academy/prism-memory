<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use Prism\Memory\Exceptions\UnsafeMemoryConfiguration;

/** @internal Immutable defaults and per-call embedding dispatch options. */
final readonly class EmbeddingRouting
{
    public function __construct(public ?string $queue = null, public ?string $connection = null)
    {
        self::name($queue, 'queue');
        self::name($connection, 'connection');
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        return new self(
            self::name($config['queue'] ?? null, 'queue'),
            self::name($config['connection'] ?? null, 'connection'),
        );
    }

    public function withOverrides(?string $queue, ?string $connection): self
    {
        return new self($queue ?? $this->queue, $connection ?? $this->connection);
    }

    private static function name(mixed $value, string $key): ?string
    {
        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            throw UnsafeMemoryConfiguration::invalidEmbeddingRoute($key);
        }

        return $value;
    }
}
