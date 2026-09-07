<?php

declare(strict_types=1);

namespace Prism\Memory;

use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Contracts\TokenCounter;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\Exceptions\UnstorableMemory;
use Prism\Memory\Jobs\EmbedMemories;
use Prism\Memory\Support\RecallSettings;
use Prism\Memory\ValueObjects\Recalled;
use Prism\Memory\ValueObjects\Recollection;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;
use Prism\Memory\ValueObjects\Weighting;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * One owner's memory in one scope.
 *
 * ## remember() does not embed inside the request
 *
 * A record is written immediately and embedded afterwards, through the
 * application's queue. That ordering is the difference between memory being
 * free at the point of use and memory being a tax on it: embedding is a
 * provider round trip, and doing it inline means every turn waits for the
 * PREVIOUS turn's bookkeeping before it can answer.
 *
 * The consequence is stated rather than hidden: a memory is not recallable
 * until its vector arrives. {@see pending()} is how an application watches that,
 * and {@see synchronously()} is how a caller who needs to read its own write
 * opts out — visibly, at the call site, rather than by a config file deciding
 * it for them.
 *
 * Nothing here assumes a queue worker exists. Laravel's default connection runs
 * jobs inline, so a fresh install behaves exactly as if embedding were
 * synchronous, and an application with a worker gets the benefit without
 * changing any code. That is the harness's Redis lesson applied: use what the
 * framework already has, never require infrastructure the installing app never
 * claimed to have.
 *
 * ## What is stored
 *
 * Observations — text that was said, with its provenance — and tool results,
 * stored verbatim. Not summaries, not extracted facts: see {@see MemoryKind}
 * for why those two remain a deliberate refusal to answer a question the spec
 * left open, and why tool results no longer are.
 *
 * Tool results are on by default, because on a real agentic corpus they are the
 * overwhelming majority of the transcript — one consumer measured 93% — so a
 * memory layer that stored only observations would be optimising the remainder.
 * `rememberToolResults: false` turns them off for a workload where tool traffic
 * genuinely is disposable, which is the shape a chat assistant has.
 *
 * No model runs in `remember()`, and no model runs in `recall()` beyond the
 * embedding call that semantic search requires by definition. So neither is a
 * billable generative operation today, and if that ever changes it has to
 * change visibly in the signature.
 */
final class Memory
{
    public function __construct(
        private readonly string $collection,
        private readonly VectorStore $store,
        private readonly Embedder $embedder,
        private readonly TokenCounter $tokens,
        private readonly Dispatcher $bus,
        private readonly CacheRepository $cache,
        private readonly RecallSettings $defaults,
        private readonly ?int $retentionSeconds = null,
        private readonly int $batchSize = 96,
        private readonly bool $synchronous = false,
        private readonly bool $rememberToolResults = true,
    ) {}

    public function collection(): string
    {
        return $this->collection;
    }

    /**
     * Embed inline instead of queueing, for a caller that must read its own write.
     *
     * A copy rather than a mutation: the memory handed out by the container is
     * shared, and a `synchronously()` call in one place must not change what
     * everywhere else does.
     */
    public function synchronously(): self
    {
        return new self(
            collection: $this->collection,
            store: $this->store,
            embedder: $this->embedder,
            tokens: $this->tokens,
            bus: $this->bus,
            cache: $this->cache,
            defaults: $this->defaults,
            retentionSeconds: $this->retentionSeconds,
            batchSize: $this->batchSize,
            synchronous: true,
            rememberToolResults: $this->rememberToolResults,
        );
    }

    /**
     * Derive memories from what was said, and store them.
     *
     * Takes `$response->messages` — the whole exchange including tool steps —
     * or a bare string, or a single message.
     *
     * @param  iterable<Message|string>|Message|string  $subject
     * @param  array<string, scalar|null>  $metadata  Merged into every record written.
     * @return list<string> The ids written, which is what {@see forget()} takes.
     */
    public function remember(iterable|Message|string $subject, array $metadata = [], ?Carbon $occurredAt = null): array
    {
        $records = [];
        $at = $occurredAt ?? Carbon::now();

        foreach ($this->observationsIn($subject) as $observation) {
            [$content, $role, $kind, $extra] = $observation;

            $records[] = new VectorRecord(
                collection: $this->collection,
                // Content-derived, so remembering the same sentence twice
                // replaces one row rather than accumulating two. A conversation
                // re-recorded after a retry does not double the store, and a
                // duplicate cannot occupy a second slot in a recall's results.
                //
                // The tool's name joins the digest for the same reason the role
                // does: an identical payload returned by two different tools is
                // two facts about the conversation, and collapsing them loses
                // which tool said it.
                id: $this->identify($content, $role, (string) ($extra['tool_name'] ?? '')),
                content: $content,
                vector: null,
                space: $this->embedder->space(),
                metadata: [
                    ...$metadata,
                    ...$extra,
                    'kind' => $kind->value,
                    'role' => $role,
                ],
                occurredAt: $at,
                expiresAt: $this->retentionSeconds === null ? null : $at->copy()->addSeconds($this->retentionSeconds),
            );
        }

        if ($records === []) {
            return [];
        }

        $this->store->upsert($records);

        $ids = array_map(fn (VectorRecord $record): string => $record->id, $records);

        if ($this->synchronous) {
            // The records are already in hand, so the synchronous path embeds
            // exactly these rather than re-reading whatever is pending. A caller
            // who asked to read their own write should not also inherit a
            // backlog somebody else left behind.
            $this->embed($records);
        } else {
            $this->bus->dispatch(new EmbedMemories($this->collection, $this->batchSize));
        }

        return $ids;
    }

    /**
     * Retrieve the memories that bear on a question.
     *
     * @param  int|null  $budget  Fill up to this many estimated tokens instead of
     *                            taking a fixed number. The caller knows their
     *                            context window; a memory layer that returns five
     *                            passages whether or not they fit has left the
     *                            hard part to them.
     * @param  array<string, scalar|null|list<scalar>>  $filter  Equality on metadata.
     */
    public function recall(
        string $query,
        ?int $limit = null,
        ?int $budget = null,
        ?Weighting $weighting = null,
        ?float $minScore = null,
        array $filter = [],
    ): Recollection {
        $limit ??= $this->defaults->limit;
        $weighting ??= $this->defaults->weighting;
        $minScore ??= $this->defaults->minScore;

        if (trim($query) === '') {
            return Recollection::empty();
        }

        $matches = $this->store->search(new VectorQuery(
            collection: $this->collection,
            vector: $this->embedQuery($query),
            space: $this->embedder->space(),
            // Over-fetch so the weighting has something to weigh. Budgeted
            // recalls also need slack: a budget can admit more short memories
            // than `limit` would have allowed.
            limit: max($limit, $limit * $this->defaults->overfetch),
            filter: $filter,
        ));

        if ($matches === []) {
            return Recollection::empty();
        }

        $now = Carbon::now();
        $scored = [];

        foreach ($matches as $match) {
            $age = max(0, $now->getTimestamp() - $match->occurredAt->getTimestamp());
            $score = $weighting->score($match->similarity, $age);

            if ($minScore !== null && $score < $minScore) {
                continue;
            }

            $metadata = $match->metadata;
            $role = $metadata['role'] ?? null;

            $scored[] = new Recalled(
                id: $match->recordId,
                content: $match->content,
                kind: MemoryKind::tryFrom((string) ($metadata['kind'] ?? '')) ?? MemoryKind::Observation,
                role: is_string($role) ? $role : null,
                similarity: $match->similarity,
                recency: $weighting->decay($age),
                score: $score,
                occurredAt: $match->occurredAt,
                metadata: $metadata,
            );
        }

        usort($scored, fn (Recalled $a, Recalled $b): int => $b->score <=> $a->score);

        if ($budget !== null) {
            return $this->fill($scored, $budget);
        }

        $taken = array_slice($scored, 0, $limit);

        return Recollection::of($taken, $this->estimate($taken));
    }

    /**
     * Remove memories. With no ids, removes everything in this scope.
     *
     * A hard delete, not a flag. "Forget this" is a request a person is
     * entitled to make about what a system knows of them, and a soft delete
     * answers it with a row that still exists — which is the wrong answer to
     * give and an expensive one to be found giving.
     *
     * @param  list<string>  $ids
     */
    public function forget(array $ids = []): int
    {
        return $ids === []
            ? $this->store->purge($this->collection)
            : $this->store->forget($this->collection, $ids);
    }

    /**
     * Forget everything that HAPPENED before this moment.
     *
     * The cut is on when the remembered thing occurred, not on when the row was
     * written — so a conversation backfilled last week from two years ago is
     * forgotten by the age of the conversation, which is what anyone asking to
     * forget old memories means.
     */
    public function forgetBefore(DateTimeInterface $before): int
    {
        return $this->store->purgeOccurredBefore($this->collection, $before);
    }

    public function count(): int
    {
        return $this->store->count($this->collection);
    }

    /**
     * How many memories are stored but not yet searchable.
     *
     * Persistently above zero means the queue is not draining, and the symptom
     * an application would otherwise see is recall quietly returning less than
     * it should.
     */
    public function pending(): int
    {
        return $this->count() - $this->store->count($this->collection, embeddedOnly: true);
    }

    /**
     * Embed one batch of whatever is waiting. Returns how many were embedded.
     *
     * Takes WHATEVER is pending in the collection rather than a list of ids the
     * caller nominated, and that is what makes it self-healing. A job that was
     * lost, a worker that died mid-run, a record written while the queue was
     * paused — all of them leave rows with no vector, and all of them are
     * repaired by the next run without anything having to remember that they
     * happened.
     *
     * Two workers draining the same collection at once will embed some of the
     * same rows twice. That is wasted tokens, not corruption: the write is
     * idempotent on (collection, id), so the second one replaces the first with
     * the same vector. Locking to avoid it would trade a bounded, rare cost for
     * an unbounded one — a lock that is held when a worker dies is a collection
     * that stops being embedded at all.
     */
    public function embedPending(?int $batch = null): int
    {
        return $this->embed($this->store->unembedded($this->collection, $batch ?? $this->batchSize));
    }

    /**
     * Embed these records and write their vectors back.
     *
     * One provider call per chunk, never one per record. Six memories from one
     * turn is one round trip.
     *
     * @param  list<VectorRecord>  $records
     */
    private function embed(array $records): int
    {
        $records = array_values(array_filter($records, fn (VectorRecord $record): bool => ! $record->isEmbedded()));

        if ($records === []) {
            return 0;
        }

        $embedded = 0;

        foreach (array_chunk($records, $this->batchSize) as $batch) {
            $vectors = $this->embedder->embed(array_map(
                fn (VectorRecord $record): string => $record->content,
                $batch,
            ));

            $written = [];

            foreach ($batch as $index => $record) {
                $written[] = $record->withVector($vectors[$index]);
            }

            $this->store->upsert($written);
            $embedded += count($written);
        }

        return $embedded;
    }

    /**
     * Take memories in rank order until the budget is spent.
     *
     * Rank order for the TAKING, chronological for the result — the two are
     * different questions. Which memories make the cut is about usefulness;
     * what order they appear in is about prompt-cache stability. See
     * {@see Recollection}.
     *
     * Does not stop at the first item that does not fit. A single long memory
     * early in the ranking would otherwise discard every shorter one behind it,
     * and the caller would get one memory where their budget had room for six.
     *
     * @param  list<Recalled>  $ranked
     */
    private function fill(array $ranked, int $budget): Recollection
    {
        $taken = [];
        $spent = 0;

        foreach ($ranked as $memory) {
            $cost = $this->tokens->count($memory->asLine());

            if ($spent + $cost > $budget) {
                continue;
            }

            $taken[] = $memory;
            $spent += $cost;
        }

        return Recollection::of($taken, $spent);
    }

    /**
     * @param  list<Recalled>  $memories
     */
    private function estimate(array $memories): int
    {
        $total = 0;

        foreach ($memories as $memory) {
            $total += $this->tokens->count($memory->asLine());
        }

        return $total;
    }

    /**
     * Embed the query, reusing an identical one within the cache window.
     *
     * Keyed on the embedding space as well as the text, so changing models
     * cannot serve a vector from the old one — which would be a cache hit that
     * produces a search in the wrong coordinate system.
     */
    private function embedQuery(string $query): Vector
    {
        if ($this->defaults->queryCacheSeconds < 1) {
            return $this->embedder->embed([$query])[0];
        }

        $key = 'prism-memory:q:'.hash('sha256', $this->embedder->space().'|'.$query);

        $packed = $this->cache->remember(
            $key,
            $this->defaults->queryCacheSeconds,
            fn (): string => $this->embedder->embed([$query])[0]->pack(),
        );

        return Vector::unpack((string) $packed);
    }

    /**
     * Content-addressed, and scoped to the collection by the store's own key.
     *
     * The role is in the digest so that the same sentence said by the user and
     * echoed by the assistant stays two memories. They are two different facts
     * about the conversation — one is a claim, the other is a confirmation —
     * and collapsing them loses which.
     */
    private function identify(string $content, ?string $role, string $tool = ''): string
    {
        return hash('sha256', ($role ?? '').'|'.$tool.'|'.$content);
    }

    /**
     * What in this subject is worth remembering, and as whom.
     *
     * System messages are skipped: a system prompt is configuration the
     * application wrote, not something that happened, and remembering it would
     * feed the model its own instructions back as recalled context.
     *
     * Tool results ARE remembered now, verbatim. They were withheld on the
     * grounds that a tool result is structured data whose useful form is
     * probably not the raw payload, and that storing it badly is worse than not
     * storing it — but that only forces a choice if the shape has to be picked
     * at WRITE time, and it does not. See {@see toolResultsIn()} and
     * {@see MemoryKind::ToolResult} for what settled it, and
     * `rememberToolResults` for turning it off.
     *
     * `->content` and not `->text()`. `UserMessage::__construct` appends a
     * `Text` part built from `content`, and `text()` concatenates every part —
     * so on a message carrying its own parts, `text()` returns the extra parts
     * PLUS the content, and remembering it would store a sentence that was
     * never said. This is the same constructor trap that doubled thread
     * messages in `prism-harness`, met from a different direction.
     *
     * @param  iterable<Message|string>|Message|string  $subject
     * @return list<array{0: string, 1: string|null, 2: MemoryKind, 3: array<string, scalar|null>}>
     */
    private function observationsIn(iterable|Message|string $subject): array
    {
        if (is_string($subject)) {
            return trim($subject) === '' ? [] : [[$subject, null, MemoryKind::Observation, []]];
        }

        $messages = $subject instanceof Message ? [$subject] : $subject;
        $observations = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($this->toolResultsIn($message) as $result) {
                    $observations[] = $result;
                }

                continue;
            }

            $observation = match (true) {
                is_string($message) => [$message, null],
                $message instanceof UserMessage => [$message->content, 'user'],
                $message instanceof AssistantMessage => [$message->content, 'assistant'],
                // Still skipped, and still named rather than falling through to
                // the refusal, so that adding a message type to Prism is a
                // failure here and not a silent omission. A system prompt is
                // configuration the application wrote, not something that
                // happened, and remembering it would feed the model its own
                // instructions back as recalled context.
                $message instanceof SystemMessage => null,
                // Anything else is refused rather than dropped. Storing nothing
                // and returning successfully is the shape of failure this
                // package is worst at detecting later: the caller believes a
                // conversation was remembered, recall returns nothing, and
                // there is no error anywhere to connect the two.
                default => throw UnstorableMemory::unrememberable(get_debug_type($message)),
            };

            if ($observation === null || trim($observation[0]) === '') {
                continue;
            }

            $observations[] = [...$observation, MemoryKind::Observation, []];
        }

        return $observations;
    }

    /**
     * Every tool result in one message, stored as it came back.
     *
     * VERBATIM, and that is the whole design. The reason this was withheld was
     * that a tool result is "structured data whose useful form is probably not
     * the raw payload" — but that only forces a choice if the shape has to be
     * picked at WRITE time. It does not. A stored payload can always be
     * summarised on the way out; a stored summary can never be un-summarised,
     * so the lossless form is the one to keep and recall is where shaping
     * belongs.
     *
     * An ERROR is still a tool result. It is flagged in metadata rather than
     * dropped, because the rows that explain what went wrong are exactly the
     * ones a store that assumed well-formed payloads would discard.
     *
     * @return list<array{0: string, 1: string|null, 2: MemoryKind, 3: array<string, scalar|null>}>
     */
    private function toolResultsIn(ToolResultMessage $message): array
    {
        if (! $this->rememberToolResults) {
            return [];
        }

        $results = [];

        foreach ($message->toolResults as $result) {
            $content = $this->payload($result->result);

            if (trim($content) === '') {
                continue;
            }

            $results[] = [
                $content,
                'tool',
                MemoryKind::ToolResult,
                [
                    // The tool's name, so a recall can be narrowed to one tool
                    // without parsing the payload back out of the content.
                    'tool_name' => $result->toolName,
                    'tool_call_id' => $result->toolCallId,
                    // Whether the payload is structured. A consumer measuring
                    // their own corpus found 371 of 375 structured and none
                    // prose, which is what makes a DETERMINISTIC projection the
                    // right first tool for shaping these on recall — no
                    // generative call, and no field silently dropped.
                    'structured' => is_array($result->result),
                ],
            ];
        }

        return $results;
    }

    /**
     * A tool result as storable text, whatever shape it arrived in.
     *
     * `result` is `int|float|string|array|null`, so all four have to land
     * somewhere. Arrays are encoded rather than flattened: flattening would
     * decide the useful form here, at write time, which is the decision this
     * whole design exists to defer to recall.
     *
     * @param  array<mixed>|int|float|string|null  $result
     */
    private function payload(int|float|string|array|null $result): string
    {
        if ($result === null) {
            return '';
        }

        if (is_array($result)) {
            return (string) json_encode($result);
        }

        return (string) $result;
    }
}
