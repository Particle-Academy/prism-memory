<?php

declare(strict_types=1);

namespace Prism\Memory\Contracts;

use Prism\Memory\ValueObjects\Vector;
use Prism\Memory\ValueObjects\VectorRecord;

/**
 * Optional atomic embedding completion for a VectorStore.
 *
 * Match collection, id, exact content and embedding space against the snapshot,
 * and require that the current record is still unembedded. Update only its
 * vector and index fields. Never insert or replace content, metadata or dates.
 * A deleted, revised or already embedded record returns false.
 *
 * Implement this capability for concurrent writers. Without it Memory re-reads
 * unembedded records before upsert, but another writer can delete or revise a
 * record between that check and the write. That compatibility fallback is not
 * atomic and cannot guarantee deletion safety during that remaining window.
 */
interface WritesEmbeddingsConditionally
{
    public function writeEmbedding(VectorRecord $snapshot, Vector $vector): bool;
}
