<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Memory\Stores\DatabaseVectorStore;
use Prism\Memory\Support\BinarySignature;
use Prism\Memory\Support\IndexSettings;
use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorQuery;
use Prism\Memory\ValueObjects\VectorRecord;

/*
|--------------------------------------------------------------------------
| What the index actually does
|--------------------------------------------------------------------------
|
| A benchmark that shows recall against store size is worth more than a
| paragraph claiming it scales. These measure two things and neither is a
| wall-clock time — that number means something different on every machine it
| is taken on, so it would tell a reader nothing and would fail in CI for
| reasons unrelated to the code.
|
| What is measured instead is the SHAPE of the work: how many bytes a search
| moves, and how often the approximate path returns what the exact path would
| have. Both are properties of the algorithm rather than of the hardware.
|
| The numbers these produce are the ones quoted in the README.
|
*/

/**
 * Vectors clustered around a handful of directions, so "relevant" means
 * something. Uniformly random vectors in high dimensions are all equidistant,
 * and a recall measurement over them measures nothing.
 *
 * @return list<Vector>
 */
function clustered(int $count, int $dimensions, int $clusters, float $spread, string $seed): array
{
    $centres = [];

    for ($c = 0; $c < $clusters; $c++) {
        $centres[] = pseudoVector("{$seed}|centre|{$c}", $dimensions);
    }

    $vectors = [];

    for ($i = 0; $i < $count; $i++) {
        $centre = $centres[$i % $clusters];
        $noise = pseudoVector("{$seed}|noise|{$i}", $dimensions);
        $values = [];

        foreach ($centre as $index => $component) {
            $values[] = $component + $spread * $noise[$index];
        }

        $vectors[] = Vector::of($values);
    }

    return $vectors;
}

/**
 * @return list<float>
 */
function pseudoVector(string $label, int $dimensions): array
{
    $bytes = '';
    $round = 0;

    while (strlen($bytes) < $dimensions) {
        $bytes .= hash('sha256', $label.'|'.$round++, true);
    }

    $values = [];

    for ($index = 0; $index < $dimensions; $index++) {
        $values[] = (ord($bytes[$index]) - 127.5) / 127.5;
    }

    return $values;
}

function seed(DatabaseVectorStore $store, array $vectors, string $collection = 'bench'): void
{
    $records = [];

    foreach ($vectors as $index => $vector) {
        $records[] = new VectorRecord(
            collection: $collection,
            id: 'r'.$index,
            content: 'memory '.$index,
            vector: $vector,
            space: 'bench:space',
        );
    }

    $store->upsert($records);
}

/**
 * A query near a cluster, the way a real question sits near the memories that
 * answer it. A uniformly random query would make the "true" neighbours an
 * arbitrary set at near-zero cosine — the hardest case there is, and not one
 * any application produces.
 */
function nearCluster(int $cluster, int $probe, int $dimensions, float $spread, string $seed): Vector
{
    $centre = pseudoVector("{$seed}|centre|{$cluster}", $dimensions);
    $noise = pseudoVector("{$seed}|query|{$cluster}|{$probe}", $dimensions);
    $values = [];

    foreach ($centre as $index => $component) {
        $values[] = $component + $spread * $noise[$index];
    }

    return Vector::of($values);
}

it('returns what an exhaustive search would, and degrades visibly rather than silently', function (): void {
    // Recall@8: of the eight memories an exact scan would return, how many does
    // the signature path also return. Measured across several candidate budgets
    // so the README can say where it starts to slip instead of quoting one
    // flattering number.
    $dimensions = 256;
    $spread = 0.9;
    $vectors = clustered(1000, $dimensions, 12, $spread, 'quality');

    $exact = new DatabaseVectorStore(DB::connection(), new IndexSettings(strategy: IndexSettings::STRATEGY_EXACT));
    seed($exact, $vectors);

    $queries = [];
    $truths = [];
    $similarities = [];

    for ($q = 0; $q < 24; $q++) {
        $query = nearCluster($q % 12, $q, $dimensions, $spread, 'quality');
        $matches = $exact->search(new VectorQuery('bench', $query, 'bench:space', limit: 8));

        $queries[$q] = $query;
        $truths[$q] = array_column($matches, 'recordId');
        $similarities = [...$similarities, ...array_column($matches, 'similarity')];
    }

    $measured = [];

    foreach ([8, 32, 128, 256] as $candidates) {
        $store = new DatabaseVectorStore(DB::connection(), new IndexSettings(candidates: $candidates));
        $found = 0;
        $possible = 0;

        foreach ($truths as $q => $truth) {
            $got = array_column($store->search(new VectorQuery('bench', $queries[$q], 'bench:space', limit: 8)), 'recordId');

            $found += count(array_intersect($truth, $got));
            $possible += count($truth);

            // The measurement is only meaningful if the approximate path really
            // did read fewer vectors than the exact one. Without this, a bug
            // that quietly made `signature` scan everything would report perfect
            // recall and look like a success.
            expect($store->lastScored)->toBeLessThanOrEqual($candidates)
                ->and($store->lastRanked)->toBe(1000);
        }

        $measured[$candidates] = $found / $possible;
    }

    fwrite(STDERR, sprintf(
        '
  recall@8 over 1000 memories (true neighbours at cosine %.2f)
',
        array_sum($similarities) / count($similarities),
    ));

    foreach ($measured as $candidates => $recall) {
        fwrite(STDERR, sprintf('    %4d candidates: %5.1f%%
', $candidates, $recall * 100));
    }

    // A floor at the shipped default, and not an equality: pinning the exact
    // figure would turn an unrelated change to the fixture into a red build with
    // no defect behind it.
    expect($measured[256])->toBeGreaterThan(0.95);

    // And the shape has to hold — a wider budget must never recall less.
    expect($measured[256])->toBeGreaterThanOrEqual($measured[32])
        ->and($measured[32])->toBeGreaterThanOrEqual($measured[8]);
});

it('scores only a bounded number of vectors however large the collection grows', function (): void {
    // The ranking pass is linear — it reads one small row per memory — but the
    // expensive half, pulling full vectors out of the database, is capped. This
    // is the property that keeps a growing store from making every turn slower
    // in proportion.
    $dimensions = 128;
    $store = new DatabaseVectorStore(DB::connection(), new IndexSettings(candidates: 64));
    $query = Vector::of(pseudoVector('growth|centre|0', $dimensions));

    $scored = [];

    foreach ([100, 400, 1600] as $size) {
        DB::table('memory_vectors')->delete();
        seed($store, clustered($size, $dimensions, 8, 0.9, 'growth'));

        $store->search(new VectorQuery('bench', $query, 'bench:space', limit: 8));

        $scored[$size] = $store->lastScored;
    }

    expect($scored[100])->toBe(64)
        ->and($scored[400])->toBe(64)
        ->and($scored[1600])->toBe(64);
});

it('reads a fraction of the bytes an exhaustive search would', function (): void {
    // The saving is not arithmetic, it is IO. At 1536 dimensions a vector is
    // 16KB of base64 and its signature is 64 characters — so the ranking pass
    // moves about 0.4% of what scoring every vector would.
    $signature = new BinarySignature('prism-memory', 256);
    $vector = Vector::of(pseudoVector('bytes', 1536));

    $vectorBytes = strlen($vector->pack());
    $signatureBytes = strlen($signature->of($vector));

    expect($signatureBytes)->toBe(64)
        ->and($signatureBytes / $vectorBytes)->toBeLessThan(0.01);

    fwrite(STDERR, sprintf(
        "  per row at 1536 dimensions: %d bytes ranked vs %d bytes scored (%.2f%%)\n",
        $signatureBytes,
        $vectorBytes,
        $signatureBytes / $vectorBytes * 100,
    ));
});

it('estimates the angle between two vectors from their signatures alone', function (): void {
    // The property everything above rests on: the fraction of bits two
    // signatures disagree on is θ/π. If that stopped holding, ranking would
    // still return results and they would be the wrong ones, so it is pinned
    // directly rather than only through its consequences.
    $signature = new BinarySignature('prism-memory', 1024);
    $dimensions = 256;

    $base = Vector::of(pseudoVector('angle|base', $dimensions));

    foreach ([0.15, 0.5, 1.2] as $spread) {
        $noise = pseudoVector('angle|noise|'.$spread, $dimensions);
        $values = [];

        foreach ($base->values as $index => $component) {
            $values[] = $component + $spread * $noise[$index];
        }

        $other = Vector::of($values);

        $estimated = cos(BinarySignature::distance(
            (string) hex2bin($signature->of($base)),
            (string) hex2bin($signature->of($other)),
        ) / 1024 * M_PI);

        // Within 0.05 of the true cosine at 1024 bits. Wide enough not to be
        // brittle, tight enough that a broken estimator could not pass.
        expect(abs($estimated - $base->cosine($other)))->toBeLessThan(0.05);
    }
});

it('agrees with itself across processes, which is what makes stored signatures readable', function (): void {
    // The hyperplanes are derived from SHA-256 rather than from a seeded PRNG
    // precisely so that the request that writes a memory, the worker that embeds
    // it, and the request that searches for it next month all produce the same
    // bits. A drift here would not error — it would make every previously stored
    // memory unfindable.
    $vector = Vector::of(pseudoVector('stability', 384));

    expect((new BinarySignature('prism-memory', 256))->of($vector))
        ->toBe((new BinarySignature('prism-memory', 256))->of($vector))
        ->and((new BinarySignature('different-seed', 256))->of($vector))
        ->not->toBe((new BinarySignature('prism-memory', 256))->of($vector));
});
