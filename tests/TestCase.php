<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\PrismMemoryServiceProvider;
use Prism\Prism\PrismServiceProvider;
use Tests\Fixtures\FakeEmbedder;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            PrismMemoryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Deterministic embeddings, so a test can assert on which memory came
        // back rather than on whether something plausible did. A real provider
        // would make every assertion here a probability.
        //
        // A SINGLETON, so a test that reaches for the embedder gets the same
        // instance the memory is using. Bound per-resolution, the call counter
        // would read zero however many calls had been made — a test that could
        // only ever pass by accident.
        $app->singleton(Embedder::class, fn (): Embedder => new FakeEmbedder);
    }

    /**
     * A stand-in for the host application's User model — memory has to work
     * against whatever the app calls its owner, not a type this package ships.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
