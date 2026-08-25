<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\ValueObjects\Recalled;
use Prism\Memory\ValueObjects\Recollection;
use Prism\Memory\ValueObjects\Weighting;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

function recalled(string $id, string $content, float $score, string $at, ?string $role = 'user'): Recalled
{
    return new Recalled(
        id: $id,
        content: $content,
        kind: MemoryKind::Observation,
        role: $role,
        similarity: $score,
        recency: 1.0,
        score: $score,
        occurredAt: Carbon::parse($at),
    );
}

/*
|--------------------------------------------------------------------------
| Prompt caching
|--------------------------------------------------------------------------
|
| Anthropic and OpenAI both price a cached prefix at a fraction of a fresh one,
| and both cache a PREFIX — a byte-identical run from the start of the request.
| Recalled memories sit inside that prefix, so a memory layer that reorders
| them defeats caching invisibly: the set is the same, the bytes are not, and
| the request gets MORE expensive while appearing to save tokens.
|
*/

it('orders by when a memory happened, not by how well it scored', function (): void {
    // Score order is unstable by nature — two memories 0.001 apart swap when a
    // third arrives, when the query is reworded, when a recency weight ticks
    // over. Chronological order cannot churn: a memory's position depends on
    // when it happened, which never changes.
    $recollection = Recollection::of([
        recalled('c', 'newest', 0.9, '2026-03-01T00:00:00Z'),
        recalled('a', 'oldest', 0.5, '2026-01-01T00:00:00Z'),
        recalled('b', 'middle', 0.7, '2026-02-01T00:00:00Z'),
    ]);

    expect(array_column($recollection->all(), 'content'))->toBe(['oldest', 'middle', 'newest'])
        ->and(array_column($recollection->ranked(), 'content'))->toBe(['newest', 'middle', 'oldest']);
});

it('breaks a timestamp tie the same way every time', function (): void {
    // `occurred_at` genuinely ties: a tool loop writes several memories inside
    // one second. An unstable tiebreak would put the prefix churn straight back
    // in, and it would be intermittent, which is worse than constant.
    $memories = [
        recalled('zebra', 'z', 0.9, '2026-01-01T00:00:00Z'),
        recalled('alpha', 'a', 0.1, '2026-01-01T00:00:00Z'),
        recalled('mike', 'm', 0.5, '2026-01-01T00:00:00Z'),
    ];

    $first = Recollection::of($memories)->digest();

    shuffle($memories);

    expect(Recollection::of($memories)->digest())->toBe($first)
        ->and(array_column(Recollection::of($memories)->all(), 'id'))->toBe(['alpha', 'mike', 'zebra']);
});

it('gives the same digest for the same memories and a different one when they change', function (): void {
    // The whole point of exposing it: a change in the provider's cache-read
    // token count stops being a mystery. Either the digest moved and the miss is
    // explained, or it did not and the cause is elsewhere.
    $memories = [recalled('a', 'one', 0.9, '2026-01-01T00:00:00Z')];

    expect(Recollection::of($memories)->digest())->toBe(Recollection::of($memories)->digest())
        ->and(Recollection::of([...$memories, recalled('b', 'two', 0.5, '2026-02-01T00:00:00Z')])->digest())
        ->not->toBe(Recollection::of($memories)->digest());
});

it('renders a timestamp that does not depend on the reader locale or the clock', function (): void {
    // A relative "3 days ago" or a localised date would change the prefix on
    // every request without anything having changed, which is a cache miss
    // caused by the formatting of the thing being cached.
    $line = recalled('a', 'the address is 4 Elm Row', 0.9, '2026-01-01T12:30:00+02:00')->asLine();

    expect($line)->toBe('[2026-01-01T10:30:00Z] user: the address is 4 Elm Row');
});

/*
|--------------------------------------------------------------------------
| Becoming context
|--------------------------------------------------------------------------
*/

it('becomes one system message rather than a set of invented turns', function (): void {
    // Replaying recollections as prior user and assistant turns tells the model
    // a fragment was the last thing said, which is a claim about the
    // conversation that is not true — and it invites the model to answer the
    // recalled question instead of the one being asked.
    $messages = Recollection::of([
        recalled('a', 'one', 0.9, '2026-01-01T00:00:00Z'),
        recalled('b', 'two', 0.8, '2026-01-02T00:00:00Z'),
    ])->asMessages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(SystemMessage::class)
        ->and($messages[0]->content)->toContain('one')->toContain('two');
});

it('contributes nothing at all when there is nothing to recall', function (): void {
    expect(Recollection::empty()->asMessages())->toBe([])
        ->and(Recollection::empty()->asContext())->toBe('')
        ->and(Recollection::empty()->isEmpty())->toBeTrue()
        ->and(Recollection::empty())->toHaveCount(0);
});

it('composes with a prompt through withSystemPrompt, which is the documented shape', function (): void {
    // Driven all the way to a request rather than stopping at the builder.
    // `toRequest()` is protected, so calling it from a test raises an Error and
    // not an Exception — and an assertion that "no Exception was thrown" would
    // then pass without the request ever having been built. Faking the provider
    // is what makes this exercise the real path.
    $fake = Prism::fake();

    $recollection = Recollection::of([recalled('a', 'the address is 4 Elm Row', 0.9, '2026-01-01T00:00:00Z')]);

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withSystemPrompt('You are a support agent.')
        ->withSystemPrompt($recollection->asContext())
        ->withPrompt('What is my address?')
        ->asText();

    $fake->assertCallCount(1);
});

it('records that withMessages and withPrompt together is refused by Prism', function (): void {
    // Not a bug in this package, and not one in Prism either — the refusal is
    // deliberate, because there is no defensible order to merge an explicit
    // message list with a prompt.
    //
    // It IS a trap, and worse than most: it only fires once recall starts
    // returning something, so it passes in development against an empty store
    // and throws the first time memory works. Pinned here so the README's
    // guidance cannot quietly stop being true — and asserted on the specific
    // failure, so a request that broke for any other reason would not satisfy it.
    Prism::fake();

    $recollection = Recollection::of([recalled('a', 'the address is 4 Elm Row', 0.9, '2026-01-01T00:00:00Z')]);

    expect(fn () => Prism::text()
        ->using('openai', 'gpt-4o')
        ->withMessages($recollection->asMessages())
        ->withPrompt('What is my address?')
        ->asText())
        ->toThrow(PrismException::class, 'You can only use `prompt` or `messages`');
});

/*
|--------------------------------------------------------------------------
| Weighting
|--------------------------------------------------------------------------
*/

it('decays by half over one half-life', function (): void {
    $weighting = new Weighting(halfLifeSeconds: 100);

    expect($weighting->decay(0))->toBe(1.0)
        ->and($weighting->decay(100))->toBe(0.5)
        ->and($weighting->decay(200))->toBe(0.25);
});

it('clamps a memory dated in the future rather than scoring it above one', function (): void {
    // Clock skew between two workers is ordinary. A mis-set timestamp must not
    // be able to outrank a genuine match.
    expect((new Weighting)->decay(-500))->toBe(1.0);
});

it('normalises the weights so a threshold means the same thing at any mix', function (): void {
    // Without this, raising the recency weight raises every score and quietly
    // disables the caller's minScore.
    $balanced = new Weighting(relevance: 1.0, recency: 1.0, halfLifeSeconds: 100);

    expect($balanced->score(1.0, 0))->toBe(1.0)
        ->and($balanced->score(-1.0, 0))->toBe(0.0)
        ->and((new Weighting(relevance: 5.0, recency: 5.0, halfLifeSeconds: 100))->score(0.5, 100))
        ->toBe($balanced->score(0.5, 100));
});

it('refuses a weighting that would rank the worst matches first', function (): void {
    expect(fn (): Weighting => new Weighting(relevance: -1.0))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('refuses a weighting where every memory scores the same', function (): void {
    // With both weights at zero the order you get back is whatever the database
    // felt like, which is not a ranking.
    expect(fn (): Weighting => new Weighting(relevance: 0.0, recency: 0.0))
        ->toThrow(InvalidArgumentException::class, 'at least one non-zero weight');
});
