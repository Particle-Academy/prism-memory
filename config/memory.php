<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default scope
    |--------------------------------------------------------------------------
    |
    | Memory is addressed by owner AND scope, so one person can hold several
    | unrelated bodies of memory without them merging. A support history and a
    | coding session are not the same memory, and one leaking into the other is
    | the model confidently telling somebody about something they never said
    | here. This is the scope used when a caller does not name one.
    |
    */

    'default_scope' => env('MEMORY_DEFAULT_SCOPE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Through Prism, which already speaks to every provider that offers them.
    | A package that embedded text its own way would be a second provider
    | integration nobody asked for.
    |
    | Changing the model here is not a free upgrade. Every memory written under
    | the previous one lives in a different coordinate system, and a similarity
    | computed across the two is a number without a meaning — so the store
    | refuses the comparison rather than returning slowly worsening answers.
    | Re-embed, or use a new scope.
    |
    */

    'embeddings' => [
        'provider' => env('MEMORY_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('MEMORY_EMBEDDINGS_MODEL', 'text-embedding-3-small'),

        // How many memories go in one provider call. Every embeddings endpoint
        // takes an array, so a turn producing six memories should cost one
        // round trip rather than six.
        'batch' => (int) env('MEMORY_EMBEDDINGS_BATCH', 96),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recall
    |--------------------------------------------------------------------------
    |
    | Recall sits in front of every turn, so these are latency settings as much
    | as quality ones.
    |
    | `overfetch` is the multiplier on how many candidates the store returns
    | before weighting picks from them. Ranking by anything other than raw
    | similarity has to rescore, and rescoring the top 8 can only reorder those
    | 8 — a memory that is the seventieth most similar and was written an hour
    | ago cannot win a recency-weighted ranking it was never entered into.
    |
    | `relevance` and `recency` are separate axes on purpose. Pure similarity
    | returns the most SIMILAR memory, which is not the most useful one: "what
    | is my billing address" matches every previous time the address came up,
    | including the one that has since been superseded. The default is
    | relevance alone, because that is what someone expects from something
    | called semantic recall — raise `recency` when newer means truer.
    |
    */

    'recall' => [
        'limit' => (int) env('MEMORY_RECALL_LIMIT', 8),
        'overfetch' => (int) env('MEMORY_RECALL_OVERFETCH', 8),
        'min_score' => env('MEMORY_RECALL_MIN_SCORE'),

        'relevance' => (float) env('MEMORY_RECALL_RELEVANCE', 1.0),
        'recency' => (float) env('MEMORY_RECALL_RECENCY', 0.0),
        'half_life' => (int) env('MEMORY_RECALL_HALF_LIFE', 7 * 24 * 60 * 60),

        // How long an identical query's embedding is reused. The single biggest
        // lever on recall latency, because embedding the query is a network
        // round trip in front of every turn. Zero disables it.
        'query_cache' => (int) env('MEMORY_RECALL_QUERY_CACHE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Seconds a memory lives without being deliberately removed. Null keeps it.
    |
    | Null is the default because a memory package that quietly expires things
    | is worse than one that keeps them: an agent that has forgotten what it was
    | told behaves exactly like an agent that was never told, and nothing on the
    | outside can tell the difference. Expiry is enforced on READ, so a memory
    | past its window is never recalled merely because nothing has pruned it.
    |
    | This is not the same thing as a delete path. `forget()` is a hard delete
    | and always available.
    |
    */

    'retention' => is_numeric($retention = env('MEMORY_RETENTION')) ? (int) $retention : null,

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Defaults to the database because that is what every Laravel app already
    | has. The harness learned this the expensive way: it defaulted its
    | ephemeral store to Redis and a fresh install threw a raw connection error
    | on any machine that had never claimed to run one.
    |
    | A real vector database is strictly better than the database driver at
    | scale and is where a large installation should end up. Register one with
    | `VectorStoreManager::extend()`; `prism-rag` resolves the same store
    | through the same manager, so it is one registration for both.
    |
    | `require_durable` refuses a store that reports itself volatile. Losing a
    | memory has no symptom, so the refusal is on by default and turning it off
    | is how an application says it meant to.
    |
    */

    'store' => env('MEMORY_STORE', 'database'),

    'require_durable' => (bool) env('MEMORY_REQUIRE_DURABLE', true),

    'drivers' => [

        'database' => [
            'driver' => 'database',
            'connection' => env('MEMORY_DB_CONNECTION'),
            'table' => 'memory_vectors',

            /*
             | How the store finds candidates.
             |
             | 'signature' — rank the collection on 32-byte fingerprints of each
             |               vector's direction, then read the full vectors of
             |               only the best few hundred and score those exactly.
             |               Linear in the collection with a constant a few
             |               hundred times smaller, and every score a caller
             |               sees is a real cosine.
             |
             | 'exact'     — read every vector in the collection. Correct, and
             |               ruinous past a few thousand memories: at 1536
             |               dimensions each one is 12KB coming out of the
             |               database. It is here so `signature`'s recall can be
             |               measured against something.
             |
             | NEITHER IS SUBLINEAR. Bucketed LSH — the obvious way to get there
             | — cannot be both bounded and correct at the similarity levels text
             | embeddings produce; the numbers are in `BinarySignature`. Past
             | roughly twenty thousand memories in one collection, register a
             | real vector database with `VectorStoreManager::extend()`.
             |
             | `bits` and `seed` both determine what a signature IS. CHANGING
             | EITHER INVALIDATES EVERY STORED SIGNATURE — old rows keep their
             | old fingerprints and stop being comparable. Change them only
             | alongside a re-index.
             */
            'index' => [
                'strategy' => env('MEMORY_INDEX_STRATEGY', 'signature'),
                'bits' => (int) env('MEMORY_INDEX_BITS', 256),
                'candidates' => (int) env('MEMORY_INDEX_CANDIDATES', 256),
                'seed' => env('MEMORY_INDEX_SEED', 'prism-memory'),
                'exact_scan_limit' => (int) env('MEMORY_INDEX_EXACT_SCAN_LIMIT', 2000),
            ],
        ],

    ],

];
