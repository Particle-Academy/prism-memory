<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Contracts\VectorStore;
use Prism\Memory\Exceptions\UnstorableMemory;
use Prism\Memory\PrismMemory;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\EmbeddingQueues;
use Tests\Fixtures\FakeEmbedder;

beforeEach(function (): void {
    EmbeddingQueues::configure();
    app()->forgetInstance(PrismMemory::class);
});

it('preserves exact legacy content identity when no caller key is supplied', function (): void {
    $memory = app(PrismMemory::class)->collection('legacy');
    $ids = $memory->remember([new UserMessage('Thanks.'), new UserMessage('Thanks.')]);

    expect($ids)->toBe([hash('sha256', 'user||Thanks.'), hash('sha256', 'user||Thanks.')])
        ->and($memory->count())->toBe(1);
});

it('keeps the same words from different callers separate with their own metadata', function (): void {
    $memory = app(PrismMemory::class)->collection('chats');
    $first = $memory->remember('Thanks.', ['chat_id' => 'one'], identity: 'chat-message:one');
    $second = $memory->remember('Thanks.', ['chat_id' => 'two'], identity: 'chat-message:two');
    $records = app(VectorStore::class)->unembedded('chats');

    expect($first)->not->toBe($second)
        ->and($first[0])->toMatch('/\Acaller:[a-f0-9]{64}\z/')
        ->and($memory->count())->toBe(2)
        ->and(array_column(array_map(fn ($record) => $record->metadata, $records), 'chat_id'))
        ->toEqualCanonicalizing(['one', 'two']);
});

it('makes retries idempotent within a caller namespace without storing the key', function (): void {
    $memory = app(PrismMemory::class)->collection('retries');
    $key = 'chat-message:private-source-key';
    $first = $memory->remember('Thanks.', ['revision' => 1], identity: $key);
    $second = $memory->remember('Thanks.', ['revision' => 2], identity: $key);
    $records = app(VectorStore::class)->unembedded('retries');

    expect($second)->toBe($first)->and($memory->count())->toBe(1)
        ->and($records[0]->metadata['revision'])->toBe(2)
        ->and(json_encode($records[0]->metadata))->not->toContain($key)
        ->and(EmbeddingQueues::payload(EmbeddingQueues::DEFAULT_DB))->not->toContain($key);
    $memory->embedPending();
    /** @var FakeEmbedder $embedder */
    $embedder = app(Embedder::class);
    expect($embedder->batches)->toBe([['Thanks.']]);
});

it('keeps repeated observations distinct and retries the complete subject deterministically', function (): void {
    $memory = app(PrismMemory::class)->collection('conversation');
    $first = $memory->remember(['Repeat.', 'Other.', 'Repeat.'], identity: 'conversation:1');
    $second = $memory->remember(['Repeat.', 'Other.', 'Repeat.'], identity: 'conversation:1');

    expect(array_unique($first))->toHaveCount(3)
        ->and($second)->toBe($first)->and($memory->count())->toBe(3);
});

it('does not shift existing child identities when a different observation is inserted', function (): void {
    $memory = app(PrismMemory::class)->collection('insertions');
    $first = $memory->remember(['One.', 'Two.'], identity: 'conversation:1');
    $second = $memory->remember(['Other.', 'One.', 'Two.'], identity: 'conversation:1');

    expect($second[1])->toBe($first[0])->and($second[2])->toBe($first[1]);
});

it('keeps native tool-call provenance in deterministic child identities', function (): void {
    $memory = app(PrismMemory::class)->collection('tools');
    $one = new ToolResult('one', 'lookup', [], 'same');
    $two = new ToolResult('two', 'lookup', [], 'same');
    $first = $memory->remember(new ToolResultMessage([$one, $two]), identity: 'conversation:1');
    $reversed = $memory->remember(new ToolResultMessage([$two, $one]), identity: 'conversation:1');

    expect($first[0])->not->toBe($first[1])
        ->and($reversed)->toBe([$first[1], $first[0]])
        ->and($memory->count())->toBe(2);
});

it('separates collections and framed key bytes without normalizing them', function (): void {
    $root = app(PrismMemory::class);
    $a = $root->collection('one')->remember('Text.', identity: 'source:é');
    $b = $root->collection('two')->remember('Text.', identity: 'source:é');
    $c = $root->collection('one')->remember('Text.', identity: "source:e\u{0301}");
    $d = $root->collection('one')->remember('Text.', identity: ' source:é ');

    expect(array_unique([$a[0], $b[0], $c[0], $d[0]]))->toHaveCount(4);
});

it('keeps content revisions and mixed identity modes explicit', function (): void {
    $memory = app(PrismMemory::class)->collection('edits');
    $old = $memory->remember('Original.', identity: 'chat-message:1');
    $new = $memory->remember('Edited.', identity: 'chat-message:1');
    $legacy = $memory->remember('Edited.');

    expect(array_unique([$old[0], $new[0], $legacy[0]]))->toHaveCount(3)
        ->and($memory->count())->toBe(3)
        ->and($memory->forget($old))->toBe(1)
        ->and($memory->count())->toBe(2);
});

it('counts UTF-8 bytes when enforcing the caller-key bound', function (): void {
    $memory = app(PrismMemory::class)->collection('unicode-bound');
    expect($memory->remember('Text.', identity: str_repeat('é', 256)))->toHaveCount(1);
});

it('rejects an invalid caller key before writes, embedding or dispatch', function (string $identity): void {
    $memory = app(PrismMemory::class)->collection('invalid-key');
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $error = null;
    try {
        $memory->remember('Text.', identity: $identity);
    } catch (UnstorableMemory $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf(UnstorableMemory::class)
        ->and($writes)->toBe([])->and($memory->count())->toBe(0)
        ->and(EmbeddingQueues::count(EmbeddingQueues::DEFAULT_DB))->toBe(0);
    /** @var FakeEmbedder $embedder */
    $embedder = app(Embedder::class);
    expect($embedder->calls)->toBe(0);
})->with([
    'empty' => [''], 'whitespace' => ['  '], 'NUL' => ["private\0key"],
    'newline' => ["private\nkey"], 'DEL' => ["private\x7Fkey"],
    'bad UTF-8' => ["\xC3\x28"], 'too many bytes' => [str_repeat('a', 513)],
]);
