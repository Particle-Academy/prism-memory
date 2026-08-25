<?php

declare(strict_types=1);

namespace Prism\Memory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Prism\Memory\PrismMemory;

/**
 * Embeds the memories that have been written but have no vector yet.
 *
 * This job is why `remember()` is cheap. Embedding is a provider round trip;
 * doing it inline would mean every turn waits for the previous turn's
 * bookkeeping before it can answer, which is a memory layer that costs the
 * request it was meant to improve.
 *
 * The payload is a COLLECTION and not a list of ids, deliberately. A job that
 * names its own rows can only ever fix the rows it named — and the rows that
 * most need fixing are the ones whose job was lost, whose worker died, or which
 * were written while the queue was paused. Draining whatever is pending means
 * every one of those is repaired by the next ordinary run, with nothing having
 * to have noticed.
 *
 * It also keeps the payload constant-size. A turn that produced two hundred
 * memories would otherwise serialise two hundred hashes into the queue.
 *
 * Nothing here assumes a queue worker exists. Laravel's default connection runs
 * jobs inline, so a fresh install behaves as though embedding were synchronous,
 * and an application with a worker gets the latency back without changing a
 * line of code.
 *
 * Deliberately NOT `ShouldBeUnique`. Uniqueness would drop a dispatch made
 * while another job is running — and the dropped one is exactly the dispatch
 * that knows about memories the running job had already read past. Two
 * overlapping drains cost some duplicate embedding calls, which is a bounded
 * waste; a dropped dispatch is a memory that never becomes searchable, which is
 * not.
 */
class EmbedMemories implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many drain passes one job will make.
     *
     * Bounded rather than "until empty" so a collection accumulating memories
     * faster than they can be embedded produces a long queue rather than one
     * job that never returns and is eventually killed mid-batch by a worker
     * timeout.
     */
    private const MAX_PASSES = 20;

    public function __construct(
        public readonly string $collection,
        public readonly ?int $batch = null,
    ) {}

    public function handle(PrismMemory $memory): void
    {
        $pass = 0;

        do {
            $embedded = $memory->collection($this->collection)->embedPending($this->batch);
        } while ($embedded > 0 && ++$pass < self::MAX_PASSES);
    }
}
