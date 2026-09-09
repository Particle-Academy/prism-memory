<?php

declare(strict_types=1);

use Prism\Memory\Facades\PrismMemory;
use Prism\Memory\Memory;
use Prism\Memory\PrismMemory as Manager;

/*
|--------------------------------------------------------------------------
| The documented entry point
|--------------------------------------------------------------------------
|
| `PrismMemory::for($user, scope: 'support')` is the first line of this
| package's README and of its page on the docs site, and until now there was no
| such class — the manager's `for()` is an instance method on an object with six
| constructor dependencies, so the documented call was a fatal error.
|
| These exist so the first thing anybody copies is the thing that is tested.
|
*/

it('resolves the manager the README documents statically', function (): void {
    expect(PrismMemory::getFacadeRoot())->toBeInstanceOf(Manager::class);
});

it('runs the exact call the README opens with', function (): void {
    $memory = PrismMemory::for('owner-1', scope: 'support');

    expect($memory)->toBeInstanceOf(Memory::class);
});

it('scopes two owners apart through the facade', function (): void {
    // The failure that would be least visible and worst: one owner's memories
    // answering another's question.
    $a = PrismMemory::address('owner-1', 'support');
    $b = PrismMemory::address('owner-2', 'support');

    expect($a)->not->toBe($b);
});
