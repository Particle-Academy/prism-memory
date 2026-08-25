<?php

declare(strict_types=1);

namespace Prism\Memory\Exceptions;

use RuntimeException;

/**
 * Thrown when a vector cannot be trusted to produce a meaningful score.
 *
 * Every case here has the same shape: the alternative to throwing is a number
 * that looks like a similarity and is not one. A NAN component makes every
 * comparison false, so the record stops being retrievable without ever
 * erroring. A zero vector divides to INF. Mismatched dimensions compare the
 * first N components of two unrelated embedding spaces and return something in
 * [-1, 1] that means nothing at all.
 *
 * A wrong answer that is shaped like a right one is the failure mode this
 * package is least able to detect later, so it is refused here.
 */
final class InvalidVector extends RuntimeException
{
    public static function empty(): self
    {
        return new self('A vector must have at least one dimension. An empty embedding is a provider response that failed without saying so.');
    }

    public static function notNumeric(int $index, string $value): self
    {
        return new self(
            "Vector component [{$index}] is the non-numeric string [{$value}]. Prism types an embedding's "
            .'components as int|string|float, so numeric strings are expected and cast — this is not one, '
            .'which means the provider returned something other than an embedding.'
        );
    }

    public static function notFinite(int $index): self
    {
        return new self(
            "Vector component [{$index}] is NAN or INF. Storing it would make every similarity computed "
            .'against this record NAN, and because NAN comparisons are always false the record would '
            .'silently stop being retrievable rather than failing.'
        );
    }

    public static function degenerate(): self
    {
        return new self(
            'This vector has zero magnitude, so it has no direction and its similarity to anything is '
            .'undefined. Returning 0.0 would be a plausible-looking number rather than an answer.'
        );
    }

    public static function unscorable(): self
    {
        return new self(
            'This vector overflows a double when squared, so every dot product involving it is INF and '
            .'every similarity computed from it is NAN or a confident zero. Embedding components sit close '
            .'to the unit interval, so a vector this large did not come from a provider.'
        );
    }

    public static function corruptPayload(): self
    {
        return new self(
            'A stored vector could not be unpacked: the payload is not base64 of a whole number of '
            .'IEEE 754 doubles. The row was not written by this package, or was truncated on the way in.'
        );
    }

    public static function dimensionMismatch(int $left, int $right): self
    {
        return new self(
            "Cannot compare a {$left}-dimensional vector with a {$right}-dimensional one. Vectors of "
            .'different lengths come from different embedding models, and similarity between two '
            .'embedding spaces is not a defined quantity.'
        );
    }
}
