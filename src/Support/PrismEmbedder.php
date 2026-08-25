<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use Prism\Memory\Contracts\Embedder;
use Prism\Memory\Exceptions\EmbeddingFailed;
use Prism\Memory\ValueObjects\Vector;
use Prism\Prism\Facades\Prism;

/**
 * Embeddings through Prism, which already speaks to every provider that has
 * them.
 *
 * A memory package that embedded text its own way would be a second provider
 * integration nobody asked for — a second set of API keys, a second retry
 * policy, a second place for a provider's endpoint change to land. Prism exists
 * so there is one of those.
 *
 * One provider call per batch, never one per input. Every embeddings endpoint
 * takes an array, and a turn that produces six memories should cost one round
 * trip. Looping would be six times the latency and six times the connection
 * overhead for exactly the same tokens.
 */
final readonly class PrismEmbedder implements Embedder
{
    public function __construct(
        private string $provider,
        private string $model,
    ) {}

    #[\Override]
    public function embed(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        $response = Prism::embeddings()
            ->using($this->provider, $this->model)
            ->fromArray($inputs)
            ->asEmbeddings();

        // The guard that matters most in this file.
        //
        // Vectors are matched to their text BY POSITION. A provider returning
        // five embeddings for six inputs does not produce five correct memories
        // and one missing one — it shifts every vector after the gap onto
        // somebody else's text, permanently, with no error at any point. The
        // memory then recalls the wrong thing, confidently, forever, and
        // nothing in the system can tell that it has happened.
        //
        // Refusing the whole batch loses one turn's memories. Accepting a
        // misaligned batch corrupts the store.
        if (count($response->embeddings) !== count($inputs)) {
            throw EmbeddingFailed::countMismatch(count($inputs), count($response->embeddings), $this->model);
        }

        $vectors = [];

        foreach ($response->embeddings as $embedding) {
            $vectors[] = Vector::fromEmbedding($embedding);
        }

        return $vectors;
    }

    /**
     * Provider and model.
     *
     * Not the width, deliberately. The width is not known until a call has been
     * made, so putting it here would mean either a network round trip to build
     * an identity or a configured number that can disagree with reality — and a
     * space identity that can be wrong is worse than one that is coarse.
     *
     * The width is checked where it is actually known: the store compares the
     * dimensions of every vector it unpacks against the query's, and refuses the
     * pair when they differ under the same space label. That is the case where
     * a provider changed a model's output width without changing its name, and
     * it fails loudly instead of producing slowly worsening answers.
     */
    #[\Override]
    public function space(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
