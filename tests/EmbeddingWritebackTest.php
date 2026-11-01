<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Memory\Stores\DatabaseVectorStore;
use Prism\Memory\Support\IndexSettings;
use Tests\Fixtures\LegacyVectorStore;
use Tests\Fixtures\WritebackScenario;

it('drops obsolete in-flight vectors and preserves current fields on SQLite', function (string $change, ?string $identity, bool $legacy): void {
    $store = new DatabaseVectorStore(DB::connection(), new IndexSettings);
    WritebackScenario::run($legacy ? new LegacyVectorStore($store) : $store, $change, $identity);
})->with(['forgotten revision', 'forgotten', 'content', 'space', 'already embedded', 'metadata'])
    ->with(['legacy identity' => [null], 'caller identity' => ['chat-message:123']])
    ->with(['atomic built-in' => [false], 'original custom-store interface' => [true]]);
