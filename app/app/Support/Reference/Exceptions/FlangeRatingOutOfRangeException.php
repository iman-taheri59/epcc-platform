<?php

declare(strict_types=1);

namespace App\Support\Reference\Exceptions;

use RuntimeException;

/**
 * Thrown when a pressure-temperature rating is asked outside the published
 * envelope of its B16.5 table: a design temperature above the group's last
 * row, or a class that the table retires (prints an ellipsis) at that
 * temperature. Both mean "the code publishes no rating here" — guessing one
 * would be unsafe, so we throw.
 */
final class FlangeRatingOutOfRangeException extends RuntimeException
{
    public static function temperatureAboveLadder(string $group, float $tempC, float $topC): self
    {
        return new self(
            "Design temperature {$tempC} deg C exceeds the top of the B16.5 Table 2-{$group} "
            . "ladder ({$topC} deg C); the standard publishes no rating above it."
        );
    }

    public static function classUnavailable(string $group, int $class, float $tempC): self
    {
        return new self(
            "B16.5 Table 2-{$group} retires Class {$class} at {$tempC} deg C "
            . '(the table prints an ellipsis); no rating exists.'
        );
    }

    public static function unknownGroup(string $group): self
    {
        return new self("Unknown B16.5 material group '{$group}'.");
    }

    public static function unknownClass(int $class, array $ladder): self
    {
        return new self(
            "Class {$class} is not a B16.5 pressure class; the ladder is "
            . implode('/', array_map(strval(...), $ladder)) . '.'
        );
    }
}
