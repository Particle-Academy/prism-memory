<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Prism\Memory\Stores\DatabaseVectorStore;
use Prism\Memory\Stores\VectorStoreManager;
use Prism\Memory\Support\IndexSettings;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;
use Tests\Fixtures\WritebackScenario;

beforeEach(function (): void {
    $profile = env('MEMORY_PGVECTOR_SCHEMA_PROFILE');
    if (! is_string($profile) || $profile === '') {
        if (env('MEMORY_PGVECTOR_SCHEMA_REQUIRED')) {
            throw new RuntimeException('The required pgvector schema profile is missing.');
        }
        $this->markTestSkipped('MEMORY_PGVECTOR_SCHEMA_PROFILE is not set — extension schema SQL was NOT exercised.');
    }
    if (! in_array($profile, ['default', 'public', 'custom'], true)
        || ! is_string(env('MEMORY_PGVECTOR_DSN')) || env('MEMORY_PGVECTOR_DSN') === '') {
        throw new RuntimeException('A known profile and explicit native Postgres DSN are required.');
    }
    if ((int) env('MEMORY_PGVECTOR_DIMENSIONS', 1536) !== 3) {
        throw new RuntimeException('The native schema fixture requires MEMORY_PGVECTOR_DIMENSIONS=3.');
    }
    $part = function (string $key): string {
        preg_match('/'.$key.'=([^;]+)/', (string) env('MEMORY_PGVECTOR_DSN'), $matches);

        return $matches[1] ?? '';
    };
    config()->set('database.connections.pgvector_schema', [
        'driver' => 'pgsql', 'host' => $part('host'), 'port' => $part('port'),
        'database' => $part('dbname'), 'username' => (string) env('MEMORY_PGVECTOR_USER'),
        'password' => (string) env('MEMORY_PGVECTOR_PASSWORD'), 'charset' => 'utf8',
        'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
    ]);
    $connection = DB::connection('pgvector_schema');
    $extension = $profile === 'custom' ? 'Memory_Ext17' : 'public';

    // Independent fixture SQL. Never relocate or remove an existing extension.
    // A profile gets its own disposable CI database, not a shared app database.
    if ($profile === 'custom') {
        $connection->statement('CREATE SCHEMA IF NOT EXISTS "Memory_Ext17"');
        // Let the published migration install into this explicit namespace.
    } else {
        // Pin the legacy fixture to public before selecting an app-first path.
        // Otherwise unqualified CREATE EXTENSION would install into that app
        // schema, and its teardown would also remove the extension it owns.
        $connection->statement('CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public');
    }
    $existing = $connection->selectOne('SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = ?', ['vector']);
    if ($existing !== null && $existing->nspname !== $extension) {
        throw new RuntimeException('The extension is already installed in another schema; the test will not move it.');
    }

    $appSchema = 'prism_memory_g194_'.bin2hex(random_bytes(6));
    $connection->statement('CREATE SCHEMA "'.$appSchema.'"');
    config()->set('memory_test_schema', $appSchema);
    $connection->statement('SET search_path TO "'.$appSchema.'"'.($profile === 'default' ? ', public' : ''));
    config()->set('memory.store', 'pgvector');
    config()->set('memory.drivers.pgvector.connection', 'pgvector_schema');
    config()->set('memory.drivers.pgvector.dimensions', 3);
    config()->set('memory.drivers.pgvector.extension_schema', $profile === 'default' ? null : $extension);
    DB::setDefaultConnection('pgvector_schema');
    Schema::clearResolvedInstance('db.schema');

    // Execute the migration applications actually publish, including its type,
    // operator class and index SQL, with the extension outside search_path.
    $migration = require __DIR__.'/../database/migrations-pgvector/0001_01_01_000000_create_memory_vectors_pgvector_table.php';
    $migration->up();
    $portable = require __DIR__.'/../database/migrations/0001_01_01_000000_create_memory_vectors_table.php';
    $portable->up();
});

afterEach(function (): void {
    $schema = config('memory_test_schema');
    if (is_string($schema) && preg_match('/\Aprism_memory_g194_[a-f0-9]{12}\z/', $schema) === 1) {
        DB::connection('pgvector_schema')->statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
    DB::setDefaultConnection('testing');
    Schema::clearResolvedInstance('db.schema');
    DB::purge('pgvector_schema');
});

it('migrates indexes and searches with the actual configured extension namespace', function (): void {
    $connection = DB::connection('pgvector_schema');
    $expected = env('MEMORY_PGVECTOR_SCHEMA_PROFILE') === 'custom' ? 'Memory_Ext17' : 'public';
    $type = $connection->selectOne("SELECT n.nspname FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid JOIN pg_namespace n ON n.oid = t.typnamespace WHERE a.attrelid = 'memory_vectors_pgvector'::regclass AND a.attname = 'embedding'");
    $index = $connection->selectOne("SELECT n.nspname, o.opcname FROM pg_index i JOIN pg_opclass o ON o.oid = i.indclass[0] JOIN pg_namespace n ON n.oid = o.opcnamespace WHERE i.indexrelid = 'memory_pgv_embedding_hnsw'::regclass");
    expect($type->nspname)->toBe($expected)
        ->and($index->nspname)->toBe($expected)
        ->and($index->opcname)->toBe('vector_cosine_ops');
    if (env('MEMORY_PGVECTOR_SCHEMA_PROFILE') !== 'default') {
        expect($connection->selectOne('SHOW search_path')->search_path)->not->toContain($expected);
    }

    $store = (new VectorStoreManager(app(), config('memory')))->store();
    $snapshot = new VectorRecord('native', 'pending', 'Pending text.', null, 'test:space', ['owner' => 'one']);
    $store->upsert([
        $snapshot,
        new VectorRecord('native', 'other', 'Different text.', Vector::of([0.0, 1.0, 0.0]), 'test:space', ['owner' => 'two']),
    ]);
    expect($store->writeEmbedding($snapshot, Vector::of([1.0, 0.0, 0.0])))->toBeTrue();
    $matches = $store->search(new VectorQuery('native', Vector::of([1.0, 0.0, 0.0]), 'test:space', filter: ['owner' => 'one']));
    expect($matches)->toHaveCount(1)->and($matches[0]->recordId)->toBe('pending')
        ->and($matches[0]->similarity)->toBeGreaterThan(0.999);

    $migration = require __DIR__.'/../database/migrations-pgvector/0001_01_01_000000_create_memory_vectors_pgvector_table.php';
    $migration->down();
    expect(Schema::hasTable('memory_vectors_pgvector'))->toBeFalse()
        ->and($connection->selectOne("SELECT count(*) AS total FROM pg_extension WHERE extname = 'vector'")->total)->toBe(1);
});

it('retains deletion and revision safety with a schema-qualified native store', function (string $change, ?string $identity): void {
    WritebackScenario::run((new VectorStoreManager(app(), config('memory')))->store(), $change, $identity);
})->with(['forgotten revision', 'forgotten', 'content', 'space', 'already embedded', 'metadata'])
    ->with(['legacy identity' => [null], 'caller identity' => ['chat-message:123']]);

it('retains deletion and revision safety with the portable store on real Postgres', function (string $change, ?string $identity): void {
    WritebackScenario::run(new DatabaseVectorStore(DB::connection('pgvector_schema'), new IndexSettings), $change, $identity);
})->with(['forgotten revision', 'forgotten', 'content', 'space', 'already embedded', 'metadata'])
    ->with(['legacy identity' => [null], 'caller identity' => ['chat-message:123']]);
