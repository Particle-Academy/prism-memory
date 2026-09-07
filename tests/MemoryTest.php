<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Contracts\TokenCounter;
use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\Jobs\EmbedMemories;
use Prism\Memory\PrismMemory;
use Prism\Memory\Stores\VectorStoreManager;
use Prism\Memory\ValueObjects\Weighting;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Owner;

function memory(?string $scope = null)
{
    return app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']), $scope)->synchronously();
}

/**
 * A memory built on overridden config.
 *
 * `PrismMemory` is a singleton and reads config at construction, so setting a
 * key after the container has resolved it changes nothing. Forgetting the
 * instance is what makes the override actually take -- without it this helper
 * would return the DEFAULT memory and the test would pass for the wrong reason.
 *
 * @param  array<string, mixed>  $config
 */
function memoryWith(array $config)
{
    foreach ($config as $key => $value) {
        config()->set('memory.'.$key, $value);
    }

    app()->forgetInstance(PrismMemory::class);

    return memory();
}

/*
|--------------------------------------------------------------------------
| remember
|--------------------------------------------------------------------------
*/

it('derives one memory per user and assistant turn', function (): void {
    $memory = memory();

    $ids = $memory->remember([
        new SystemMessage('You are helpful.'),
        new UserMessage('My billing address is 4 Elm Row.'),
        new AssistantMessage('Noted.'),
    ]);

    expect($ids)->toHaveCount(2)
        ->and($memory->count())->toBe(2);
});

it('skips the system prompt, which is configuration rather than something said', function (): void {
    // Remembering it would feed the model its own instructions back as recalled
    // context, which is a different thing from having been told them.
    $memory = memory();

    $memory->remember([new SystemMessage('You are helpful.')]);

    expect($memory->count())->toBe(0);
});

it('remembers a tool result verbatim, with the tool that produced it', function (): void {
    // Withheld until now on the grounds that a tool result is structured data
    // whose useful form is probably not the raw payload. That only forces a
    // choice if the shape is picked at WRITE time — so the payload is stored as
    // it came and shaping belongs on recall.
    $memory = memory()->synchronously();

    $memory->remember([new ToolResultMessage([
        new ToolResult('call-1', 'searchKnowledge', [], ['results' => [['title' => 'Elm Row']]]),
    ])]);

    $recalled = $memory->recall('Elm Row')->all();

    expect($memory->count())->toBe(1)
        ->and($recalled[0]->content)->toBe('{"results":[{"title":"Elm Row"}]}')
        ->and($recalled[0]->kind)->toBe(MemoryKind::ToolResult);
});

it('stores a tool result that is an error, because that is still a tool result', function (): void {
    // A consumer's corpus carried four of these out of 375 — a tool throwing
    // against a column a migration had not created. A store that assumed
    // payloads were well-formed would reject exactly the rows that explain what
    // went wrong.
    $memory = memory()->synchronously();

    $memory->remember([new ToolResultMessage([
        new ToolResult('call-1', 'migrate', [], 'SQLSTATE[42703]: Undefined column'),
    ])]);

    $recalled = $memory->recall('undefined column')->all();

    expect($memory->count())->toBe(1)
        ->and($recalled[0]->content)->toContain('Undefined column')
        ->and($recalled[0]->kind)->toBe(MemoryKind::ToolResult);
});

it('lets a recall exclude tool results, which is where shaping belongs', function (): void {
    // The filter-on-read half. Storing verbatim only pays off if a caller who
    // does not want the payloads can say so without them having been dropped on
    // write, because a stored payload can be summarised on the way out and a
    // stored summary can never be un-summarised.
    $memory = memory()->synchronously();

    $memory->remember([
        new UserMessage('Where do I live?'),
        new ToolResultMessage([new ToolResult('call-1', 'lookup', [], ['address' => '4 Elm Row'])]),
    ]);

    $everything = $memory->recall('Elm Row')->all();
    $prose = $memory->recall('Elm Row', filter: ['kind' => 'observation'])->all();

    expect($everything)->toHaveCount(2)
        ->and($prose)->toHaveCount(1)
        ->and($prose[0]->kind)->toBe(MemoryKind::Observation);
});

it('keeps an identical payload from two different tools as two memories', function (): void {
    // The tool's name is in the digest for the same reason the role is: the
    // same bytes returned by two tools are two facts about the conversation,
    // and collapsing them loses which tool said it.
    $memory = memory()->synchronously();

    $memory->remember([new ToolResultMessage([
        new ToolResult('call-1', 'getKnowledge', [], ['v' => 1]),
        new ToolResult('call-2', 'searchKnowledge', [], ['v' => 1]),
    ])]);

    expect($memory->count())->toBe(2);
});

it('can be told tool traffic is disposable, for a workload where it is', function (): void {
    // A chat assistant calling a weather tool has no use for last week's
    // forecast payload. Off is a decision an application makes, not the default.
    $memory = memoryWith(['remember_tool_results' => false]);

    $memory->remember([new ToolResultMessage([new ToolResult('call-1', 'lookup', [], 'result')])]);

    expect($memory->count())->toBe(0);
});

it('remembers a message content rather than what text() concatenates', function (): void {
    // `UserMessage::__construct` appends a Text part built from `content`, and
    // `text()` concatenates every part — so on a message carrying its own parts,
    // text() returns the extra parts PLUS the content. Remembering that would
    // store a sentence nobody said. The same constructor trap that doubled
    // thread messages in prism-harness, met from a different direction.
    $memory = memory();

    $memory->remember([new UserMessage('the real content', [new Text('a caption')])]);

    expect(DB::table('memory_vectors')->value('content'))->toBe('the real content');
});

it('writes one row for the same sentence remembered twice', function (): void {
    // A conversation re-recorded after a retry must not double the store, and a
    // duplicate must not be able to occupy two slots in one recall's results.
    $memory = memory();

    $memory->remember('My billing address is 4 Elm Row.');
    $memory->remember('My billing address is 4 Elm Row.');

    expect($memory->count())->toBe(1);
});

it('keeps the same sentence as two memories when two different people said it', function (): void {
    // A claim and a confirmation are two different facts about a conversation.
    $memory = memory();

    $memory->remember([
        new UserMessage('The deploy is finished.'),
        new AssistantMessage('The deploy is finished.'),
    ]);

    expect($memory->count())->toBe(2);
});

it('keeps one owner memories away from another', function (): void {
    $memory = app(PrismMemory::class);

    $ada = $memory->for(Owner::create(['name' => 'Ada']))->synchronously();
    $grace = $memory->for(Owner::create(['name' => 'Grace']))->synchronously();

    $ada->remember('Ada prefers dark mode.');

    expect($ada->count())->toBe(1)->and($grace->count())->toBe(0);
});

it('keeps one owner scopes apart, because a support chat is not a coding session', function (): void {
    $owner = Owner::create(['name' => 'Ada']);
    $memory = app(PrismMemory::class);

    $support = $memory->for($owner, 'support')->synchronously();
    $coding = $memory->for($owner, 'coding')->synchronously();

    $support->remember('The invoice was wrong.');

    expect($support->count())->toBe(1)->and($coding->count())->toBe(0);
});

it('does not leak the application namespace layout into the collection name', function (): void {
    // The collection ends up in cache keys, queue payloads and anything an
    // operator is looking at. prism-harness hashes its session keys for the same
    // reason, and the two packages agreeing is worth more than either choice.
    $collection = app(PrismMemory::class)->address(Owner::create(['name' => 'Ada']), 'support');

    expect($collection)->not->toContain('\\')
        ->and($collection)->not->toContain('Owner')
        ->and($collection)->toEndWith(':support');
});

/*
|--------------------------------------------------------------------------
| Embedding is not paid for inside the request
|--------------------------------------------------------------------------
*/

it('queues embedding rather than making the turn wait for it', function (): void {
    Queue::fake();

    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']));
    $memory->remember('Something worth remembering.');

    Queue::assertPushed(EmbedMemories::class);

    // The row exists immediately; it is simply not searchable yet. That is the
    // trade, stated rather than hidden.
    expect($memory->count())->toBe(1)
        ->and($memory->pending())->toBe(1);
});

it('embeds a whole turn in one provider call rather than one per memory', function (): void {
    $embedder = app(Embedder::class);

    memory()->remember([
        new UserMessage('one'),
        new AssistantMessage('two'),
        new UserMessage('three'),
        new AssistantMessage('four'),
    ]);

    expect($embedder->calls)->toBe(1)
        ->and($embedder->batches[0])->toHaveCount(4);
});

it('honours the configured batch size instead of ignoring it', function (): void {
    // Built directly rather than through the container: the singleton captured
    // config at registration, so setting it afterwards would leave the test
    // passing against the default and proving nothing about the config key.
    $embedder = app(Embedder::class);

    $memory = new PrismMemory(
        stores: app(VectorStoreManager::class),
        embedder: $embedder,
        tokens: app(TokenCounter::class),
        bus: app(Dispatcher::class),
        cache: app(CacheRepository::class),
        config: [...config('memory'), 'embeddings' => ['batch' => 2]],
    );

    $memory->for(Owner::create(['name' => 'Ada']))->synchronously()->remember(['one', 'two', 'three', 'four', 'five']);

    // Five memories at two per call is three calls, not one and not five.
    expect($embedder->calls)->toBe(3)
        ->and($embedder->batches[0])->toHaveCount(2)
        ->and($embedder->batches[2])->toHaveCount(1);
});

it('makes a memory searchable once its vector arrives', function (): void {
    Queue::fake();

    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']));
    $memory->remember('The billing address is 4 Elm Row.');

    expect($memory->recall('billing address'))->toHaveCount(0);

    $memory->embedPending();

    expect($memory->pending())->toBe(0)
        ->and($memory->recall('billing address'))->toHaveCount(1);
});

it('repairs records whose job never ran, without being told which they were', function (): void {
    // The payload is a collection and not a list of ids precisely so that the
    // rows most in need of repair — the ones whose job was lost — are the ones
    // the next ordinary run picks up.
    Queue::fake();

    $memory = app(PrismMemory::class)->for(Owner::create(['name' => 'Ada']));
    $memory->remember('Written while the queue was down.');

    expect($memory->pending())->toBe(1);

    (new EmbedMemories($memory->collection()))->handle(app(PrismMemory::class));

    expect($memory->pending())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| recall
|--------------------------------------------------------------------------
*/

it('returns the memory that bears on the question rather than the newest one', function (): void {
    $memory = memory();

    $memory->remember('My billing address is 4 Elm Row.');
    $memory->remember('The deploy pipeline runs on Tuesdays.');
    $memory->remember('I prefer dark mode in the editor.');

    $recalled = $memory->recall('billing address', limit: 1);

    expect($recalled)->toHaveCount(1)
        ->and($recalled->all()[0]->content)->toContain('billing address');
});

it('returns nothing for a blank query rather than everything', function (): void {
    $memory = memory();
    $memory->remember('Something.');

    expect($memory->recall('   ')->isEmpty())->toBeTrue();
});

it('lets the caller weigh recency against relevance', function (): void {
    // Pure similarity returns the most SIMILAR memory, which is not the most
    // useful one: a superseded address matches just as well as the current one.
    $memory = memory();

    $memory->remember('My billing address is 4 Elm Row.', occurredAt: Carbon::now()->subYears(2));
    $memory->remember('My billing address is 9 Oak Lane.', occurredAt: Carbon::now());

    $byRelevance = $memory->recall('billing address', limit: 1, weighting: Weighting::relevanceOnly());
    $byRecency = $memory->recall('billing address', limit: 1, weighting: new Weighting(relevance: 0.2, recency: 0.8));

    expect($byRecency->all()[0]->content)->toContain('9 Oak Lane')
        ->and($byRelevance->all()[0]->similarity)->toBeGreaterThan(0.0);
});

it('keeps similarity unmixed so a caller can see why something ranked', function (): void {
    // One blended number says a memory came third and nothing about whether it
    // was a close match that was old or a weak match that was fresh. Those call
    // for opposite fixes.
    $memory = memory();
    $memory->remember('billing address', occurredAt: Carbon::now()->subWeek());

    $recalled = $memory->recall('billing address', weighting: new Weighting(relevance: 0.5, recency: 0.5))->all()[0];

    expect($recalled->similarity)->toBeGreaterThan(0.9)
        ->and($recalled->recency)->toBeGreaterThan(0.49)->toBeLessThan(0.51)
        ->and($recalled->score)->toBeLessThan($recalled->similarity);
});

it('fills a token budget instead of returning a fixed number that may not fit', function (): void {
    $memory = memory();

    foreach (range(1, 20) as $index) {
        $memory->remember("Memory number {$index} about billing and addresses.", occurredAt: Carbon::now()->subMinutes($index));
    }

    $small = $memory->recall('billing', limit: 20, budget: 60);
    $large = $memory->recall('billing', limit: 20, budget: 400);

    expect($small->estimatedTokens)->toBeLessThanOrEqual(60)
        ->and($large->count())->toBeGreaterThan($small->count());
});

it('does not let one long memory discard every shorter one behind it', function (): void {
    // Stopping at the first item that does not fit would hand back one memory
    // where the budget had room for several.
    $memory = memory();

    $memory->remember(str_repeat('a very long billing recollection ', 40), occurredAt: Carbon::now()->subMinute());
    $memory->remember('billing short one', occurredAt: Carbon::now()->subMinutes(2));
    $memory->remember('billing short two', occurredAt: Carbon::now()->subMinutes(3));

    expect($memory->recall('billing', limit: 10, budget: 40)->count())->toBe(2);
});

it('applies a minimum score', function (): void {
    $memory = memory();

    $memory->remember('billing address');
    $memory->remember('entirely unrelated subject matter');

    expect($memory->recall('billing address', minScore: 0.9))->toHaveCount(1);
});

it('reuses an identical query embedding instead of paying for it again', function (): void {
    // Embedding the query is a network round trip in front of every turn, and
    // applications ask the same question more often than seems likely.
    $memory = memory();
    $memory->remember('billing address');

    $embedder = app(Embedder::class);
    $before = $embedder->calls;

    $memory->recall('billing address');
    $memory->recall('billing address');

    expect($embedder->calls - $before)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| forget
|--------------------------------------------------------------------------
*/

it('hard-deletes rather than flagging, because "forget me" has to mean it', function (): void {
    $memory = memory();

    $ids = $memory->remember('My billing address is 4 Elm Row.');

    expect($memory->forget($ids))->toBe(1)
        ->and($memory->count())->toBe(0)
        ->and(DB::table('memory_vectors')->count())->toBe(0);
});

it('forgets a whole scope', function (): void {
    $memory = memory();

    $memory->remember(['one', 'two']);
    $memory->remember('three');

    expect($memory->forget())->toBe(3)->and($memory->count())->toBe(0);
});

it('forgets everything before a date', function (): void {
    $memory = memory();

    $memory->remember('old news', occurredAt: Carbon::now()->subYear());
    $memory->remember('current news', occurredAt: Carbon::now());

    expect($memory->forgetBefore(Carbon::now()->subMonth()))->toBe(1)
        ->and($memory->count())->toBe(1);
});
