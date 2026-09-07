<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pgvector table, published rather than auto-loaded.
 *
 * NOT in `database/migrations` on purpose. That directory is loaded for every
 * installation, and this migration requires Postgres and the `vector`
 * extension — running it on the SQLite database a fresh Laravel app ships with
 * would fail the very first `migrate` for the majority of installations that
 * are perfectly well served by the portable driver.
 *
 * Publish it deliberately:
 *
 *     php artisan vendor:publish --tag=memory-pgvector-migrations
 *
 * It creates a SEPARATE table from `memory_vectors`, so the two can coexist and
 * an application can move between them without a destructive schema change —
 * and, more to the point, so publishing this cannot corrupt a table that
 * already holds memories.
 *
 * ## The width is baked in
 *
 * pgvector indexes a fixed-width column, so `MEMORY_PGVECTOR_DIMENSIONS` must
 * match the embedding model: 1536 for `text-embedding-3-small`, 3072 for
 * `-large`. Changing the model means a new column and a re-embed, which is true
 * of the portable driver too — it is only louder here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dimensions = (int) env('MEMORY_PGVECTOR_DIMENSIONS', 1536);

        // Requires a role that may install extensions. On a managed Postgres
        // this is frequently NOT the application's role — the failure arrives
        // here rather than at first search, which is the better of the two
        // places for it to arrive.
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('memory_vectors_pgvector', function (Blueprint $table): void {
            $table->id();
            $table->string('collection');
            $table->string('record_id', 191);
            $table->text('content');
            $table->string('space', 191);
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Keyed on the CALLER-OWNED id, which is what makes writes
            // idempotent for both prism-memory and prism-rag.
            $table->unique(['collection', 'record_id'], 'memory_pgv_identity_unique');
            $table->index('expires_at', 'memory_pgv_expires_index');
        });

        DB::statement("ALTER TABLE memory_vectors_pgvector ADD COLUMN embedding vector({$dimensions})");

        // Filtered to the embedded rows. A record written before its vector
        // arrives is the normal path when embedding is queued, and indexing the
        // NULLs would carry them through every graph build for nothing.
        DB::statement(
            'CREATE INDEX memory_pgv_embedding_hnsw ON memory_vectors_pgvector '
            .'USING hnsw (embedding vector_cosine_ops) WHERE embedding IS NOT NULL'
        );

        // The search filters on these before it ranks, and on a large table the
        // planner needs them to avoid reading the collection it was told to skip.
        DB::statement(
            'CREATE INDEX memory_pgv_scope_index ON memory_vectors_pgvector (collection, space)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_vectors_pgvector');
    }
};
