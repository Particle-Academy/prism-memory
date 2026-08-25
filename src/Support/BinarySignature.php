<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use InvalidArgumentException;
use Prism\Memory\ValueObjects\Vector;

/**
 * A compact stand-in for a vector, so recall does not have to read the vectors.
 *
 * The bottleneck in a database-backed vector search is not the arithmetic, it
 * is the bytes. Cosine over 1536 doubles takes about 15 microseconds; the 12KB
 * that vector occupies takes far longer to get out of the database and into
 * PHP. Scoring ten thousand memories means moving 120MB per recall, on every
 * turn.
 *
 * So each vector also gets a signature: one bit per random hyperplane,
 * recording which side of it the vector falls on. Two vectors pointing in
 * similar directions agree on most bits, and the fraction of bits they disagree
 * on estimates the angle between them directly — Hamming distance over `bits`
 * is θ/π. At 256 bits that is 32 bytes instead of 12KB, and it is enough to
 * rank.
 *
 * Ranking, not answering. The signature picks the candidates and the real
 * vectors decide the order, so every score a caller sees is an exact cosine
 * rather than an estimate. The approximation is confined to which memories got
 * considered.
 *
 * ## What was tried first, and why it is not this
 *
 * The obvious use of the same bits is classic LSH: cut them into bands, index
 * each band as a bucket, and read only the rows sharing a bucket with the
 * query. That is genuinely sublinear, and it does not work here.
 *
 * The probability two vectors agree on one hyperplane is 1 - θ/π, and text
 * embeddings put genuinely relevant query-document pairs at a cosine around
 * 0.4 to 0.6 — an angle of 53° to 66°, so a per-bit agreement around 0.63 to
 * 0.71. Requiring a whole band of bits to agree is that raised to the width of
 * the band. Computed rather than guessed:
 *
 *   6 bands x 12 bits, 1-bit probing:  1.9% of rows read,  18% recall at 0.40
 *   8 bands x 10 bits, 2-bit probing: 43.8% of rows read,  87% recall at 0.40
 *   6 bands x  6 bits, 1-bit probing: 65.6% of rows read,  87% recall at 0.40
 *
 * There is no setting that is both bounded and correct. Tight bands miss most
 * of the memories they were asked for; loose ones read half the collection,
 * which is a full scan wearing an index's clothes. The recall failure is the
 * bad one: it does not error, it just returns worse answers, and it gets blamed
 * on the embeddings.
 *
 * Sublinear search over embeddings needs a graph or a partitioned index — HNSW,
 * IVF — and those need a real vector database. That is what the store contract
 * is an interface for. What this driver can honestly offer is a linear scan
 * with a constant a few hundred times smaller, and a measured number in the
 * README rather than a claim.
 */
final class BinarySignature
{
    /**
     * Roughly one dimension in three participates in each hyperplane.
     * Achlioptas' density: sparse enough to be cheap, dense enough to preserve
     * distances.
     */
    private const DENSITY_CUTOFF = 85; // of 255

    /** @var array<int, int>|null */
    private static ?array $popcount = null;

    /**
     * @var array<int, list<array{plus: list<int>, minus: list<int>}>>
     */
    private array $planes = [];

    public function __construct(
        private readonly string $seed = 'prism-memory',
        private readonly int $bits = 256,
    ) {
        if ($bits < 8 || $bits % 8 !== 0) {
            throw new InvalidArgumentException(
                "A signature of {$bits} bits cannot be stored: signatures are packed into whole bytes, so "
                .'the width must be a multiple of 8 and at least 8.'
            );
        }
    }

    public function bits(): int
    {
        return $this->bits;
    }

    /**
     * The vector's signature, as hex.
     *
     * Hex rather than raw bytes because this goes in a text column, and a
     * binary column is where the three databases stop agreeing — PDO hands back
     * a stream for Postgres `bytea` and a string everywhere else.
     */
    public function of(Vector $vector): string
    {
        $bytes = '';
        $byte = 0;
        $position = 0;

        foreach ($this->planes($vector->dimensions()) as $plane) {
            $projection = 0.0;
            $values = $vector->values;

            foreach ($plane['plus'] as $index) {
                $projection += $values[$index];
            }

            foreach ($plane['minus'] as $index) {
                $projection -= $values[$index];
            }

            $byte = ($byte << 1) | ($projection >= 0.0 ? 1 : 0);

            if (++$position % 8 === 0) {
                $bytes .= chr($byte);
                $byte = 0;
            }
        }

        return bin2hex($bytes);
    }

    /**
     * How many bits two signatures disagree on. Lower is more similar.
     *
     * `count_chars` does the counting in C and returns at most 256 entries
     * whatever the signature's width, so the PHP-level loop is bounded by the
     * number of DISTINCT byte values rather than by the length. That matters:
     * this runs once per row in the collection, and a per-byte PHP loop would
     * put the cost back where the whole design was trying to take it from.
     */
    public static function distance(string $left, string $right): int
    {
        if (strlen($left) !== strlen($right)) {
            throw new InvalidArgumentException(
                'Cannot compare signatures of different widths. Signature width is fixed by configuration, '
                .'so a mismatch means rows written under one setting are being searched under another — '
                .'change it only alongside a re-index.'
            );
        }

        $table = self::$popcount ??= self::popcountTable();
        $distance = 0;

        foreach (count_chars($left ^ $right, 1) as $byte => $occurrences) {
            $distance += $table[$byte] * $occurrences;
        }

        return $distance;
    }

    /**
     * @return array<int, int>
     */
    private static function popcountTable(): array
    {
        $table = [];

        for ($byte = 0; $byte < 256; $byte++) {
            $table[$byte] = substr_count(decbin($byte), '1');
        }

        return $table;
    }

    /**
     * Built once per process per width and held.
     *
     * The hyperplanes must be identical in every process that ever touches the
     * store — the request that writes a memory, the worker that embeds it, and
     * the request that searches for it a month later on another machine. A
     * seeded `mt_srand` is not guaranteed across PHP builds and `random_bytes`
     * is guaranteed not to be.
     *
     * Hashing a label to a byte stream is deterministic by definition, has no
     * global state for anything else in the process to disturb, and — the part
     * that matters for this ecosystem — produces the SAME hyperplanes in
     * TypeScript and Python. When `prism-ts` and `prism-py` grow memory ports,
     * they will be able to read rows this one wrote. An LCG would have quietly
     * made that impossible.
     *
     * @return list<array{plus: list<int>, minus: list<int>}>
     */
    private function planes(int $dimensions): array
    {
        if (isset($this->planes[$dimensions])) {
            return $this->planes[$dimensions];
        }

        $planes = [];

        for ($plane = 0; $plane < $this->bits; $plane++) {
            // Two bytes per dimension: the first decides whether the dimension
            // takes part, the second decides its sign. Splitting them keeps the
            // two decisions independent, which one byte would not.
            $stream = $this->bytes("{$this->seed}|{$dimensions}|{$plane}", $dimensions * 2);

            $plus = [];
            $minus = [];

            for ($dimension = 0; $dimension < $dimensions; $dimension++) {
                if (ord($stream[$dimension * 2]) >= self::DENSITY_CUTOFF) {
                    continue;
                }

                if (ord($stream[$dimension * 2 + 1]) < 128) {
                    $plus[] = $dimension;
                } else {
                    $minus[] = $dimension;
                }
            }

            $planes[] = ['plus' => $plus, 'minus' => $minus];
        }

        return $this->planes[$dimensions] = $planes;
    }

    private function bytes(string $label, int $length): string
    {
        $stream = '';
        $counter = 0;

        while (strlen($stream) < $length) {
            $stream .= hash('sha256', $label.'|'.$counter++, true);
        }

        return substr($stream, 0, $length);
    }
}
