<?php

declare(strict_types=1);

namespace Prism\Memory;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Contracts\TokenCounter;
use Prism\Memory\Stores\VectorStoreManager;
use Prism\Memory\Support\RecallSettings;

/**
 * The entry point: resolve a memory for an owner.
 *
 *     $memory = PrismMemory::for($user, scope: 'support');
 *
 * Addressed by owner AND scope, the same way `prism-harness` addresses a
 * session — and for the same reason. One user holds several unrelated
 * conversations at once, and a support chat's memories bleeding into a coding
 * session's is not a small bug: it is the model confidently telling somebody
 * about something they never said in this context.
 *
 * ## This package does not own the conversation
 *
 * `prism-harness` owns threads. This reads them and stores DERIVED
 * representations, which is why nothing here resolves, replays or writes a
 * thread, and why {@see Memory::remember()} takes messages rather than fetching
 * them. Two packages both claiming to be where a conversation lives is the
 * failure decision 0008 exists to prevent, and the seam between them is that
 * one owns the record and the other owns what was made of it.
 *
 * There is no dependency on `prism-harness` either. The two compose through
 * Prism's own message value objects, so an application can use memory without
 * sessions, sessions without memory, or both.
 */
class PrismMemory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly VectorStoreManager $stores,
        protected readonly Embedder $embedder,
        protected readonly TokenCounter $tokens,
        protected readonly Dispatcher $bus,
        protected readonly CacheRepository $cache,
        protected readonly array $config = [],
    ) {}

    public function for(Model|string $owner, ?string $scope = null): Memory
    {
        return $this->collection($this->address($owner, $scope ?? $this->defaultScope()));
    }

    /**
     * A memory bound to a raw collection name.
     *
     * The low-level door. {@see EmbedMemories} comes back through it, because a
     * queued job knows which collection it was dispatched for and has no
     * business rehydrating the model that owned it — an owner that was deleted
     * between the dispatch and the run would otherwise fail the job rather than
     * embedding rows that still exist.
     */
    public function collection(string $collection): Memory
    {
        return new Memory(
            collection: $collection,
            store: $this->stores->store(),
            embedder: $this->embedder,
            tokens: $this->tokens,
            bus: $this->bus,
            cache: $this->cache,
            defaults: $this->recallSettings(),
            retentionSeconds: $this->retentionSeconds(),
            batchSize: $this->batchSize(),
        );
    }

    /**
     * The collection name for an owner and scope.
     *
     * The morph class is hashed rather than interpolated. A fully-qualified
     * class name carries backslashes, and this string ends up in cache keys,
     * queue payloads and anything an operator is looking at — none of which
     * should be leaking the application's namespace layout, and some of which
     * handle backslashes badly. `prism-harness` hashes its session keys for the
     * same reason, and the two packages agreeing on that is worth more than
     * either choice on its own.
     */
    public function address(Model|string $owner, string $scope): string
    {
        if (is_string($owner)) {
            return 's:'.substr(hash('sha256', $owner), 0, 16).':'.$scope;
        }

        return 'm:'.substr(hash('sha256', $owner->getMorphClass()), 0, 12)
            .':'.((string) $owner->getKey())
            .':'.$scope;
    }

    public function stores(): VectorStoreManager
    {
        return $this->stores;
    }

    public function defaultScope(): string
    {
        $scope = $this->config['default_scope'] ?? 'default';

        return is_string($scope) ? $scope : 'default';
    }

    /**
     * How many memories go in one provider call.
     *
     * Every embeddings endpoint takes an array, so a turn producing six
     * memories should cost one round trip. Floored at 1 rather than trusted:
     * a batch size of zero would make `array_chunk` throw from inside a queued
     * job, which is a long way from the config line that caused it.
     */
    protected function batchSize(): int
    {
        $embeddings = $this->config['embeddings'] ?? [];
        $batch = is_array($embeddings) ? ($embeddings['batch'] ?? 96) : 96;

        return max(1, (int) $batch);
    }

    protected function recallSettings(): RecallSettings
    {
        $recall = $this->config['recall'] ?? [];

        return RecallSettings::fromArray(is_array($recall) ? $recall : []);
    }

    /**
     * How long a memory lives without being deliberately removed.
     *
     * Null — keep it — is the default, because a memory package that quietly
     * expires things is worse than one that keeps them: an agent that has
     * forgotten something it was told behaves as though it was never told, and
     * nobody can tell the difference from the outside.
     *
     * Retention is a policy the application owns, so it says so explicitly. The
     * question of what retention SHOULD mean for derived representations — what
     * happens to a summary containing a fact whose source expired — is open and
     * recorded in the README, because it cannot be answered before the question
     * of what gets stored is.
     */
    protected function retentionSeconds(): ?int
    {
        $retention = $this->config['retention'] ?? null;

        return is_int($retention) && $retention > 0 ? $retention : null;
    }
}
