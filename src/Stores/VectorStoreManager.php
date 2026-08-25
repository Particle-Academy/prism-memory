<?php

declare(strict_types=1);

namespace Prism\Memory\Stores;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Exceptions\UnsafeMemoryConfiguration;
use Prism\Memory\Support\IndexSettings;

/**
 * Resolves the vector store, and refuses a configuration that would lose it.
 *
 * The guard is the point of this class. A store that reports itself Volatile is
 * refused unless the application has said, in as many words, that memory here
 * is allowed to be a cache.
 *
 * `prism-harness` makes the same check for the same reason and its exception
 * text names a real incident: a sibling project kept de-duplication state in a
 * cache a deploy could clear, and one `cache:clear` between two backfills would
 * have silently re-awarded every contribution with nothing in the logs.
 *
 * Memory's version of that mistake is quieter still. A flushed memory store
 * does not error and does not degrade to a default — it produces an agent that
 * has forgotten something a user told it and carries on confidently, which
 * looks from the outside exactly like an agent that was never told. There is no
 * symptom to notice.
 */
class VectorStoreManager
{
    /** @var array<string, Closure(array<string, mixed>, Container): VectorStore> */
    protected array $custom = [];

    protected ?VectorStore $resolved = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly array $config,
    ) {}

    /**
     * Register a driver — pgvector, Qdrant, whatever the installation runs.
     *
     * The extension point is here rather than in a config-only driver list
     * because a real vector database needs a client the package cannot
     * construct, and `prism-rag` will resolve the same store through the same
     * manager. One registration, both packages.
     *
     * @param  Closure(array<string, mixed>, Container): VectorStore  $factory
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->custom[$name] = $factory;
        $this->resolved = null;

        return $this;
    }

    public function store(): VectorStore
    {
        if ($this->resolved instanceof VectorStore) {
            return $this->resolved;
        }

        $name = $this->driverName();
        $store = $this->make($name);

        // Checked at resolve time rather than at boot, so it fires in the same
        // place whether the store comes from a config file, is swapped in a
        // test, or is registered at runtime — and so a misconfiguration cannot
        // lie dormant until the first thing someone needed remembered is gone.
        if ($this->requiresDurable() && ! $store->durability()->isDurable()) {
            throw UnsafeMemoryConfiguration::volatileStore($name);
        }

        return $this->resolved = $store;
    }

    protected function driverName(): string
    {
        $store = $this->config['store'] ?? null;

        return is_string($store) ? $store : 'database';
    }

    /**
     * Whether memory here has to survive a deploy.
     *
     * On by default. An application that genuinely wants a disposable working
     * set — a memory scoped to a single run, where the alternative to
     * remembering is asking again rather than being wrong — turns it off, and
     * the fact that it had to is the record that somebody decided.
     */
    protected function requiresDurable(): bool
    {
        return (bool) ($this->config['require_durable'] ?? true);
    }

    protected function make(string $name): VectorStore
    {
        $drivers = $this->config['drivers'] ?? [];
        $driver = is_array($drivers) ? ($drivers[$name] ?? null) : null;

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])(is_array($driver) ? $driver : [], $this->container);
        }

        if (! is_array($driver)) {
            throw UnsafeMemoryConfiguration::unknownDriver($name);
        }

        return match ($driver['driver'] ?? $name) {
            'database' => new DatabaseVectorStore(
                connection: $this->container->make(DatabaseManager::class)
                    ->connection($driver['connection'] ?? null),
                index: IndexSettings::fromArray(is_array($driver['index'] ?? null) ? $driver['index'] : []),
                table: (string) ($driver['table'] ?? 'memory_vectors'),
            ),
            default => throw UnsafeMemoryConfiguration::unknownDriver($name),
        };
    }
}
