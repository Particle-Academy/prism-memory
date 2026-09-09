<?php

declare(strict_types=1);

namespace Prism\Memory\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Prism\Memory\Memory;
use Prism\Memory\PrismMemory as Manager;
use Prism\Memory\Stores\VectorStoreManager;

/**
 * The entry point this package's own README has always documented.
 *
 * IT DID NOT EXIST UNTIL NOW, and that is the whole reason this file has a
 * comment. Every example here and on the docs site opened with
 * `PrismMemory::for($user, scope: 'support')` — a static call to an instance
 * method on a manager with six constructor dependencies. It could not work.
 * Anyone following the first code block got `Non-static method ... cannot be
 * called statically`, and the only correct usage was buried three hundred lines
 * further down as `app(PrismMemory::class)`.
 *
 * Found by `prism-parity`'s factcheck, which verifies that a class named in a
 * `php` block actually exists under a psr-4 root. It had been reporting
 * `Prism\Memory\Facades\PrismMemory` as a nonexistent class for as long as the
 * page had existed; the finding was real and nobody had read it.
 *
 * The fix is the facade rather than the docs, because `PrismHarness` in the
 * sibling package is the same shape — so the documented API was the
 * ecosystem's convention and the package was the thing out of step.
 *
 * @method static Memory for(Model|string $owner, ?string $scope = null)
 * @method static Memory collection(string $collection)
 * @method static string address(Model|string $owner, string $scope)
 * @method static VectorStoreManager stores()
 * @method static string defaultScope()
 *
 * @see Manager
 */
final class PrismMemory extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
