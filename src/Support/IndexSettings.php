<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use InvalidArgumentException;

/**
 * How the database store finds candidates.
 *
 * Two strategies, and the choice is a real trade rather than a tuning knob.
 *
 * `signature` reads one small row per memory — a 32-byte fingerprint instead of
 * a 12KB vector — ranks them by Hamming distance, and then reads the full
 * vectors of only the best few hundred to score exactly. Linear in the size of
 * the collection, with a constant a few hundred times smaller, and the scores a
 * caller sees are exact cosines rather than estimates. What is approximate is
 * which memories got considered.
 *
 * `exact` reads every embedded vector in the collection and scores all of them.
 * Correct by construction and ruinous past a few thousand memories, because at
 * 1536 dimensions each one is 12KB coming out of the database. It exists so
 * that `signature`'s recall can be measured against something, and for
 * collections small enough that the difference does not matter.
 *
 * Neither is sublinear. {@see BinarySignature} records why bucketed LSH — the
 * obvious way to get there — does not survive contact with the similarity
 * levels text embeddings actually produce, with the numbers. Sublinear search
 * needs HNSW or IVF, which needs a real vector database, which is what the
 * store contract is an interface for.
 */
final readonly class IndexSettings
{
    public const STRATEGY_SIGNATURE = 'signature';

    public const STRATEGY_EXACT = 'exact';

    public function __construct(
        public string $strategy = self::STRATEGY_SIGNATURE,
        /**
         * Signature width.
         *
         * Wider ranks more faithfully and costs more bytes per row, linearly.
         * 256 bits is 32 bytes — a four-hundredth of a 1536-dimensional vector
         * — and is enough to pick a few hundred candidates out of thousands.
         *
         * CHANGING IT INVALIDATES EVERY STORED SIGNATURE: existing rows keep
         * their old width and cannot be compared with new ones. Change it only
         * alongside a re-index.
         */
        public int $bits = 256,
        /**
         * How many candidates go through to exact scoring.
         *
         * The overfetch that buys recall back. A recall asking for 8 memories
         * scores several hundred, so a memory the signature ranked twentieth on
         * an estimated angle still gets its exact cosine computed.
         */
        public int $candidates = 256,
        public string $seed = 'prism-memory',
        /**
         * A hard ceiling on full vectors read in `exact` mode.
         *
         * Not a safety net — a truncation. Exceeding it means the newest N are
         * scored and older memories are invisible, which is why `exact` is not
         * the default and why this number is small enough to notice.
         */
        public int $exactScanLimit = 2_000,
    ) {
        if (! in_array($strategy, [self::STRATEGY_SIGNATURE, self::STRATEGY_EXACT], true)) {
            throw new InvalidArgumentException(
                "Unknown vector index strategy [{$strategy}]. Use 'signature' to rank on compact "
                ."fingerprints and score the best candidates exactly, or 'exact' to read every vector in "
                .'the collection.'
            );
        }

        if ($candidates < 1) {
            throw new InvalidArgumentException('At least one candidate must reach exact scoring.');
        }

        if ($exactScanLimit < 1) {
            throw new InvalidArgumentException('An exact scan must be allowed at least one row.');
        }
    }

    public function isApproximate(): bool
    {
        return $this->strategy === self::STRATEGY_SIGNATURE;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            strategy: is_string($config['strategy'] ?? null) ? $config['strategy'] : self::STRATEGY_SIGNATURE,
            bits: (int) ($config['bits'] ?? 256),
            candidates: (int) ($config['candidates'] ?? 256),
            seed: is_string($config['seed'] ?? null) ? $config['seed'] : 'prism-memory',
            exactScanLimit: (int) ($config['exact_scan_limit'] ?? 2_000),
        );
    }
}
