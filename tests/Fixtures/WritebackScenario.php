<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Assert;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Contracts\TokenCounter;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Memory;
use Prism\Memory\Support\RecallSettings;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;

final class WritebackScenario
{
    public static function run(VectorStore $store, string $change, ?string $identity): void
    {
        Bus::fake();
        $embedder = new class implements Embedder
        {
            public ?Closure $during = null;

            public function embed(array $inputs): array
            {
                ($this->during)();

                return array_fill(0, count($inputs), Vector::of([1.0, 0.0, 0.0]));
            }

            public function space(): string
            {
                return 'test:space';
            }
        };
        $memory = new Memory('writeback', $store, $embedder, app(TokenCounter::class), app(Dispatcher::class), app(Repository::class), new RecallSettings);
        $oldIds = $memory->remember('Old revision.', ['version' => 1], identity: $identity);
        $snapshot = $store->unembedded('writeback')[0];
        $embedder->during = function () use ($memory, $store, $snapshot, $oldIds, $identity, $change): void {
            if ($change === 'forgotten revision') {
                Assert::assertSame(1, $memory->forget($oldIds));
                Assert::assertSame(0, $memory->count());
                $memory->remember('New revision.', identity: $identity);
                Assert::assertSame(1, $memory->count());

                return;
            }
            if ($change === 'forgotten') {
                Assert::assertSame(1, $memory->forget($oldIds));

                return;
            }

            $store->upsert([new VectorRecord(
                collection: $snapshot->collection,
                id: $snapshot->id,
                content: $change === 'content' ? 'New revision.' : $snapshot->content,
                vector: $change === 'already embedded' ? Vector::of([0.0, 1.0, 0.0]) : null,
                space: $change === 'space' ? 'test:new-space' : $snapshot->space,
                metadata: ['version' => 2],
                occurredAt: Carbon::parse('2020-01-02T03:04:05Z'),
                expiresAt: Carbon::parse('2099-01-02T03:04:05Z'),
            )]);
        };

        $written = $memory->embedPending(1);
        Assert::assertSame($change === 'metadata' ? 1 : 0, $written, 'Only completed embeddings count, not obsolete results.');
        Assert::assertSame($change === 'forgotten' ? 0 : 1, $memory->count(), 'The deleted revision was resurrected by embedding writeback.');

        if ($change === 'forgotten') {
            return;
        }
        if ($change === 'already embedded' || $change === 'metadata') {
            $expected = Vector::of($change === 'metadata' ? [1.0, 0.0, 0.0] : [0.0, 1.0, 0.0]);
            $matches = $store->search(new VectorQuery('writeback', $expected, 'test:space'));
            Assert::assertCount(1, $matches);
            Assert::assertGreaterThan(0.999, $matches[0]->similarity);
            Assert::assertSame(['version' => 2], $matches[0]->metadata);
            Assert::assertSame('2020-01-02 03:04:05', $matches[0]->occurredAt->format('Y-m-d H:i:s'));

            return;
        }
        Assert::assertSame(0, $store->count('writeback', true));
        $current = $store->unembedded('writeback')[0];
        Assert::assertSame($change === 'space' ? 'test:new-space' : 'test:space', $current->space);
        Assert::assertSame($change === 'space' ? 'Old revision.' : 'New revision.', $current->content);
    }
}
