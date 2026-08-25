<?php

declare(strict_types=1);

namespace Prism\Memory\Exceptions;

use RuntimeException;

/**
 * Thrown at resolve time when memory is pointed somewhere it cannot survive.
 *
 * The reason this is loud rather than a log line is that the failure it
 * prevents has no symptom. A lost tool approval is a missing row somebody
 * eventually goes looking for; a lost memory is an agent that behaves as though
 * it was never told something, which is indistinguishable from an agent that
 * genuinely was not.
 *
 * So the configuration is refused where it is made, and the message names both
 * ways out rather than only saying no.
 */
final class UnsafeMemoryConfiguration extends RuntimeException
{
    public static function volatileStore(string $driver): self
    {
        return new self(
            "Memory is configured to use the [{$driver}] store, which reports itself as volatile — its "
            ."contents can be flushed, evicted, or lost in a deploy.\n\n"
            .'That is refused by default because losing a memory has no symptom. Nothing errors and nothing '
            .'degrades to a default: the agent simply stops knowing something a user told it, and behaves '
            ."exactly as it would have if it had never been told.\n\n"
            .'Either point memory at a durable store such as [database], or — if this really is meant to be '
            .'a disposable working set for a single run — set `memory.require_durable` to false and say so '
            .'deliberately.'
        );
    }

    public static function unknownDriver(string $name): self
    {
        return new self(
            "Memory names the store [{$name}], which is not configured. Add it under `memory.drivers`, "
            .'register it at runtime with `VectorStoreManager::extend()`, or point `memory.store` at one '
            .'that exists.'
        );
    }
}
