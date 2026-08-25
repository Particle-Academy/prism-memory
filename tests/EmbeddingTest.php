<?php

declare(strict_types=1);

use Prism\Memory\Exceptions\EmbeddingFailed;
use Prism\Memory\Support\PrismEmbedder;
use Prism\Memory\ValueObjects\Vector;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;

it('sends one request for a whole batch, not one per input', function (): void {
    // Every embeddings endpoint takes an array. A turn producing six memories
    // should cost one round trip, and an implementation that loops internally
    // is six times the latency for exactly the same tokens.
    $fake = Prism::fake([
        EmbeddingsResponseFake::make()->withEmbeddings([
            new Embedding([1.0, 0.0]),
            new Embedding([0.0, 1.0]),
            new Embedding([1.0, 1.0]),
        ]),
    ]);

    $vectors = (new PrismEmbedder('openai', 'text-embedding-3-small'))->embed(['one', 'two', 'three']);

    $fake->assertCallCount(1);

    expect($vectors)->toHaveCount(3)
        ->and($vectors[0])->toBeInstanceOf(Vector::class)
        ->and($vectors[1]->toArray())->toBe([0.0, 1.0]);
});

it('refuses the whole batch when a provider returns the wrong number of vectors', function (): void {
    // The guard that matters most in this package.
    //
    // Vectors are matched to their text BY POSITION — there is no id to check
    // against. A response one short does not lose one memory, it shifts every
    // later vector onto somebody else's sentence, and that stores cleanly,
    // searches cleanly, and returns the wrong memory forever.
    //
    // Losing a turn's memories is recoverable. Corrupting the store is not.
    Prism::fake([
        EmbeddingsResponseFake::make()->withEmbeddings([
            new Embedding([1.0, 0.0]),
            new Embedding([0.0, 1.0]),
        ]),
    ]);

    expect(fn (): array => (new PrismEmbedder('openai', 'text-embedding-3-small'))->embed(['one', 'two', 'three']))
        ->toThrow(EmbeddingFailed::class, 'returned 2 vectors for 3 inputs');
});

it('makes no request at all for an empty batch', function (): void {
    $fake = Prism::fake();

    expect((new PrismEmbedder('openai', 'text-embedding-3-small'))->embed([]))->toBe([]);

    $fake->assertCallCount(0);
});

it('identifies the embedding space by provider and model', function (): void {
    expect((new PrismEmbedder('openai', 'text-embedding-3-small'))->space())
        ->toBe('openai:text-embedding-3-small');
});
