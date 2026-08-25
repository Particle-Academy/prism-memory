<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Prism\Memory\Contracts\Embedder;
use Prism\Memory\ValueObjects\Vector;

/**
 * Deterministic embeddings with a usable notion of "similar".
 *
 * A hash of the whole string would be deterministic and useless: two sentences
 * about billing would be as far apart as a sentence about billing and one about
 * the weather, so no test could assert that recall found the RIGHT memory —
 * only that it found one.
 *
 * So this is a bag-of-words model. Each word is hashed to a fixed direction and
 * the directions are summed, which means texts sharing vocabulary point the
 * same way. Crude, and enough to make "billing address" retrieve the memory
 * about billing rather than the one about deployments — which is the property
 * the tests are actually about.
 */
final class FakeEmbedder implements Embedder
{
    public int $calls = 0;

    /** @var list<list<string>> */
    public array $batches = [];

    public function __construct(
        public readonly int $dimensions = 64,
        private readonly string $space = 'fake:bag-of-words',
    ) {}

    #[\Override]
    public function embed(array $inputs): array
    {
        $this->calls++;
        $this->batches[] = array_values($inputs);

        return array_map($this->vectorFor(...), array_values($inputs));
    }

    #[\Override]
    public function space(): string
    {
        return $this->space;
    }

    private function vectorFor(string $text): Vector
    {
        $values = array_fill(0, $this->dimensions, 0.0);
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            foreach ($this->directionFor($word) as $index => $component) {
                $values[$index] += $component;
            }
        }

        // A text of only punctuation would otherwise be a zero vector, which
        // Vector refuses — correctly, but that is not what these tests are for.
        if (array_sum(array_map(abs(...), $values)) === 0.0) {
            $values[0] = 1.0;
        }

        return Vector::of($values);
    }

    /**
     * @return list<float>
     */
    private function directionFor(string $word): array
    {
        // Enough bytes for every dimension to be independent. Repeating a
        // 32-byte digest would make dimension 40 a copy of dimension 8, and the
        // embedder would have a third of the resolution it claims.
        $bytes = '';
        $round = 0;

        while (strlen($bytes) < $this->dimensions) {
            $bytes .= hash('sha256', $word.'|'.$round++, true);
        }

        $direction = [];

        for ($index = 0; $index < $this->dimensions; $index++) {
            $direction[] = (ord($bytes[$index]) - 127.5) / 127.5;
        }

        return $direction;
    }
}
