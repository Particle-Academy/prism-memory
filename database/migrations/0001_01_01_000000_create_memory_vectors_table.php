<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_vectors', function (Blueprint $table): void {
            $table->id();

            // The namespace a search is scoped to. `prism-memory` composes it
            // from the owner and the scope; `prism-rag` will use a corpus name.
            // The store itself attaches no meaning to it beyond partitioning.
            $table->string('collection');

            // Caller-owned identity, which is what makes writing idempotent —
            // remembering the same sentence twice replaces one row rather than
            // accumulating two. `prism-rag` needs the same property for
            // re-ingestion, so it is in the contract rather than in this
            // package's own layer.
            $table->string('record_id', 191);

            $table->text('content');

            // Base64 of packed IEEE 754 doubles, not a binary column: PDO hands
            // back a stream for Postgres bytea and a string everywhere else, and
            // a per-database branch on the read path is a per-database bug on
            // the read path. Nullable, because a record is written immediately
            // and embedded afterwards — an unembedded row is the normal state
            // for as long as it takes the queue to catch up, not an error.
            $table->longText('vector')->nullable();
            $table->unsignedSmallInteger('dimensions')->nullable();

            // A compact fingerprint of the vector's DIRECTION: one bit per
            // random hyperplane, 256 of them by default, as hex. This is what a
            // search actually reads. At 32 bytes it is a four-hundredth of a
            // 1536-dimensional vector, so ranking the whole collection costs
            // megabytes rather than gigabytes — and the full vectors of only the
            // best few hundred are then read to score them exactly.
            $table->string('signature', 512)->nullable();

            // Which embedding model produced the vector. Filtered on at search
            // time so that changing models cannot silently compare coordinates
            // from two unrelated spaces.
            $table->string('space', 191);

            $table->json('metadata');

            // When the remembered thing HAPPENED, which is not when the row was
            // written. Recency weighting reads this, so a backfilled
            // conversation ranks by the age of the conversation rather than by
            // the age of the import.
            $table->timestamp('occurred_at');

            // Null means "keep until deliberately removed". Enforced on read, so
            // an expired memory can never be recalled just because nothing has
            // pruned it yet.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // The identity the store upserts on.
            $table->unique(['collection', 'record_id'], 'memory_vectors_identity_unique');

            // Every read starts here: one collection, one embedding space. The
            // recency ordering that `exact` mode uses is the third column.
            $table->index(['collection', 'space', 'occurred_at'], 'memory_vectors_scan_index');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_vectors');
    }
};
