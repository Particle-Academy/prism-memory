<?php

declare(strict_types=1);

use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Exceptions\UnsafeMemoryConfiguration;
use Prism\Memory\PrismMemory;
use Tests\Fixtures\EmbeddingQueues;

it('keeps unspecified embedding dispatch on the framework default connection and queue', function (): void {
    EmbeddingQueues::configure();
    $memory = app(PrismMemory::class)->collection('queue-default');
    $memory->remember('A pending memory.');

    expect(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB, 'default'))->toBe(1)
        ->and(EmbeddingQueues::count(EmbeddingQueues::CONFIGURED_DB))->toBe(0)
        ->and($memory->pending())->toBe(1);
});

it('sends the real queued payload to the configured connection and queue', function (): void {
    EmbeddingQueues::configure();
    config()->set('memory.embeddings.queue', 'memories');
    config()->set('memory.embeddings.connection', 'memory-configured');
    $memory = app(PrismMemory::class)->collection('queue-configured');
    $memory->remember('A configured memory.');

    expect(EmbeddingQueues::count(EmbeddingQueues::CONFIGURED_DB, 'memories'))->toBe(1)
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0)
        ->and($memory->pending())->toBe(1);
});

it('overrides dispatch for one call without mutating the memory defaults', function (): void {
    EmbeddingQueues::configure();
    config()->set('memory.embeddings.queue', 'memories');
    config()->set('memory.embeddings.connection', 'memory-configured');
    $memory = app(PrismMemory::class)->collection('queue-per-call');
    $memory->remember('Urgent.', queue: 'urgent', connection: 'memory-override');
    $memory->remember('Ordinary.');

    expect(EmbeddingQueues::count(EmbeddingQueues::OVERRIDE_DB, 'urgent'))->toBe(1)
        ->and(EmbeddingQueues::count(EmbeddingQueues::CONFIGURED_DB, 'memories'))->toBe(1)
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0);
});

it('can override one dispatch option while inheriting the other', function (): void {
    EmbeddingQueues::configure();
    config()->set('memory.embeddings.queue', 'memories');
    config()->set('memory.embeddings.connection', 'memory-configured');
    $memory = app(PrismMemory::class)->collection('queue-independent');
    $memory->remember('Queue override.', queue: 'urgent');
    $memory->remember('Connection override.', connection: 'memory-override');

    expect(EmbeddingQueues::count(EmbeddingQueues::CONFIGURED_DB, 'urgent'))->toBe(1)
        ->and(EmbeddingQueues::count(EmbeddingQueues::OVERRIDE_DB, 'memories'))->toBe(1);
});

it('keeps synchronous copies off every queue and preserves the original routing', function (): void {
    EmbeddingQueues::configure();
    config()->set('memory.embeddings.queue', 'memories');
    config()->set('memory.embeddings.connection', 'memory-configured');
    $memory = app(PrismMemory::class)->collection('queue-sync');
    $memory->synchronously()->remember('Inline.', queue: 'urgent', connection: 'memory-override');

    expect($memory->pending())->toBe(0)
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0)
        ->and(EmbeddingQueues::count(EmbeddingQueues::CONFIGURED_DB))->toBe(0)
        ->and(EmbeddingQueues::count(EmbeddingQueues::OVERRIDE_DB))->toBe(0);
    $memory->remember('Deferred.');
    expect(EmbeddingQueues::count(EmbeddingQueues::CONFIGURED_DB, 'memories'))->toBe(1);
});

it('does not dispatch a job for an empty subject even with routing overrides', function (): void {
    EmbeddingQueues::configure();
    $memory = app(PrismMemory::class)->collection('queue-empty');

    expect($memory->remember('', queue: 'urgent', connection: 'memory-override'))->toBe([])
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0)
        ->and(EmbeddingQueues::count(EmbeddingQueues::OVERRIDE_DB))->toBe(0);
});

it('rejects malformed configured routing before records or jobs are written', function (string $option, mixed $value): void {
    EmbeddingQueues::configure();
    config()->set('memory.embeddings.'.$option, $value);
    app()->forgetInstance(PrismMemory::class);

    expect(fn () => app(PrismMemory::class)->collection('invalid-route')->remember('Text.'))
        ->toThrow(UnsafeMemoryConfiguration::class)
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0)
        ->and(app(Embedder::class)->calls)->toBe(0);
})->with(['queue', 'connection'])->with(['empty' => [''], 'blank' => ['  '], 'number' => [7], 'array' => [['route']]]);

it('rejects a blank per-call override before records or jobs are written', function (string $option): void {
    EmbeddingQueues::configure();
    $memory = app(PrismMemory::class)->collection('invalid-override');
    expect(fn () => $memory->remember('Text.', ...[$option => ' ']))->toThrow(UnsafeMemoryConfiguration::class)
        ->and($memory->count())->toBe(0)
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0);
})->with(['queue', 'connection']);
