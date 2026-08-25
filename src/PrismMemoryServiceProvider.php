<?php

declare(strict_types=1);

namespace Prism\Memory;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Contracts\TokenCounter;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Stores\VectorStoreManager;
use Prism\Memory\Support\HeuristicTokenCounter;
use Prism\Memory\Support\PrismEmbedder;

class PrismMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/memory.php', 'memory');

        $this->app->singleton(VectorStoreManager::class, fn ($app): VectorStoreManager => new VectorStoreManager(
            container: $app,
            config: $app['config']->get('memory', []),
        ));

        // Bound to the CONTRACT as well as the manager, so `prism-rag` — and
        // anything else that needs vectors — resolves the same store the
        // application already configured rather than standing up a second one.
        // Two vector abstractions in one ecosystem is what makes an ecosystem a
        // collection, and two instances of one abstraction is the same mistake
        // wearing a smaller hat.
        $this->app->bind(VectorStore::class, fn ($app): VectorStore => $app->make(VectorStoreManager::class)->store());

        $this->app->bind(Embedder::class, function ($app): Embedder {
            $config = $app['config']->get('memory.embeddings', []);

            return new PrismEmbedder(
                provider: (string) ($config['provider'] ?? 'openai'),
                model: (string) ($config['model'] ?? 'text-embedding-3-small'),
            );
        });

        // An estimate, and the README says so. Bind your own when the budget
        // has to hold exactly.
        $this->app->bind(TokenCounter::class, fn (): TokenCounter => new HeuristicTokenCounter);

        $this->app->singleton(PrismMemory::class, fn ($app): PrismMemory => new PrismMemory(
            stores: $app->make(VectorStoreManager::class),
            embedder: $app->make(Embedder::class),
            tokens: $app->make(TokenCounter::class),
            bus: $app->make(Dispatcher::class),
            cache: $app->make(CacheRepository::class),
            config: $app['config']->get('memory', []),
        ));
    }

    public function boot(): void
    {
        // Loaded rather than only publishable, so memory works on install with
        // no setup step. Publish them when you need to change the schema.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/memory.php' => config_path('memory.php'),
            ], 'memory-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'memory-migrations');
        }
    }
}
