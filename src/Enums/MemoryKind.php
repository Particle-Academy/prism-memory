<?php

declare(strict_types=1);

namespace Prism\Memory\Enums;

/**
 * What a stored record IS.
 *
 * There is exactly one case, and that is a deliberate statement rather than an
 * unfinished enum.
 *
 * `specs/prism-memory.md` leaves "what is stored — messages, summaries, or
 * facts?" open on purpose, and names it the central design question. Adding
 * `Summary` and `Fact` here before that question is answered would settle it
 * quietly: the cases would exist, something would populate them, and the
 * decision would have been made by whoever built first rather than by anyone
 * who weighed it.
 *
 * So this slice stores observations — text that was actually said, with its
 * provenance — which is the substrate all three candidate answers share. A
 * summary and an extracted fact are also text with provenance; what separates
 * them is who wrote the text and whether a model was paid to do it. Those cases
 * land here additively once the question is answered, and nothing stored today
 * has to move.
 */
enum MemoryKind: string
{
    /**
     * Something that was said, stored as said.
     *
     * No model wrote it, so there is no second place for the model to be wrong,
     * and the record is auditable against the thread it came from.
     */
    case Observation = 'observation';
}
