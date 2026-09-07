<?php

declare(strict_types=1);

use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\Harness\MemoryContextRecall;
use Prism\Memory\Harness\MemoryEvictionSink;
use Prism\Memory\PrismMemory;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Owner;

/*
|--------------------------------------------------------------------------
| The prism-harness adapters
|--------------------------------------------------------------------------
|
| `prism-harness` is a require-dev dependency, not a runtime one. These classes
| are the bridge an application wires when it has both packages; nothing in
| prism-memory resolves them, so an installation with only memory never loads
| the files.
|
| The property worth protecting hardest is that the sink and the recall agree
| about the scope. They once did not — in the harness's own Lab integration —
| and it failed silently, because a lookup that matches nothing is
| indistinguishable from a conversation with nothing to find.
|
*/

function harnessOwner(): Owner
{
    return Owner::create(['name' => 'Ada']);
}

it('writes an evicted turn into memory and finds it again on the same scope', function (): void {
    // The whole point, end to end: what leaves the window is reachable by
    // meaning afterwards.
    $owner = harnessOwner();
    $memory = app(PrismMemory::class);

    $sink = new MemoryEvictionSink($memory, $owner);
    $recall = new MemoryContextRecall($memory, $owner);

    $sink->store([new UserMessage('The reference for the disputed charge is INV-4471-QK.')], 'thread-1');

    // Embedding is queued; a caller reading its own write says so at the call
    // site. The sink does not, because eviction is bookkeeping and must not
    // put a provider round trip in front of the turn being sent.
    $memory->for($owner, 'thread-1')->embedPending();

    expect($recall->recall('disputed charge reference', 'thread-1', 500))
        ->toContain('INV-4471-QK');
});

it('does not find a turn evicted from a DIFFERENT conversation', function (): void {
    // The scope is the isolation boundary. Without it every conversation an
    // owner has ever held would answer every recall.
    $owner = harnessOwner();
    $memory = app(PrismMemory::class);

    (new MemoryEvictionSink($memory, $owner))->store(
        [new UserMessage('The reference is INV-4471-QK.')],
        'thread-1',
    );
    $memory->for($owner, 'thread-1')->embedPending();

    expect((new MemoryContextRecall($memory, $owner))->recall('reference', 'thread-2', 500))
        ->toBe('');
});

it('stores tool results, which are most of an agentic transcript', function (): void {
    // A sink keeping only the prose would keep the half of the conversation
    // least likely to hold the answer — one consumer measured tool traffic at
    // 93% of theirs.
    $owner = harnessOwner();
    $memory = app(PrismMemory::class);

    (new MemoryEvictionSink($memory, $owner))->store([
        new AssistantMessage('', [new ToolCall(id: 'c1', name: 'lookup', arguments: [])]),
        new ToolResultMessage([new ToolResult('c1', 'lookup', [], ['address' => '4 Elm Row'])]),
    ], 'thread-1');

    $stored = $memory->for($owner, 'thread-1');
    $stored->embedPending();

    expect($stored->count())->toBeGreaterThan(0)
        ->and($stored->recall('Elm Row')->all()[0]->kind)->toBe(MemoryKind::ToolResult);
});

it('marks what compaction pushed out, so a caller can tell it from a deliberate memory', function (): void {
    $owner = harnessOwner();
    $memory = app(PrismMemory::class);

    $memory->for($owner, 'thread-1')->synchronously()->remember('Deliberately remembered.');
    (new MemoryEvictionSink($memory, $owner))->store([new UserMessage('Pushed out of the window.')], 'thread-1');
    $memory->for($owner, 'thread-1')->embedPending();

    $evictedOnly = new MemoryContextRecall($memory, $owner, evictedOnly: true);
    $everything = new MemoryContextRecall($memory, $owner);

    expect($evictedOnly->recall('window remembered', 'thread-1', 500))
        ->toContain('Pushed out of the window.')
        ->not->toContain('Deliberately remembered.')
        // The default reaches BOTH, because "what was I told earlier" does not
        // mean "only the part that fell out of the window".
        ->and($everything->recall('window remembered', 'thread-1', 500))
        ->toContain('Deliberately remembered.');
});

it('returns an empty string when there is nothing, rather than a placeholder', function (): void {
    // The tool reports empty as "nothing relevant was found". A placeholder
    // here would put words in the model's context that nobody said.
    $memory = app(PrismMemory::class);

    expect((new MemoryContextRecall($memory, harnessOwner()))->recall('anything', 'empty-thread', 500))
        ->toBe('');
});

it('does not lose a whole eviction because one message could not be stored', function (): void {
    // A sink is custody. `remember()` refuses a message type it cannot store,
    // and handing it a batch would take the entire turn down with it — the
    // harness catches that and carries on, so the visible result is a turn
    // silently unstored because one part of it was unusual.
    $owner = harnessOwner();
    $memory = app(PrismMemory::class);

    (new MemoryEvictionSink($memory, $owner))->store([
        new UserMessage('This one is fine.'),
        new SystemMessage('Skipped by remember().'),
        new UserMessage('And so is this one.'),
    ], 'thread-1');

    $stored = $memory->for($owner, 'thread-1');
    $stored->embedPending();

    expect($stored->count())->toBe(2);
});
