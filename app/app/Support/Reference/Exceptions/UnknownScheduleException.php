<?php

declare(strict_types=1);

namespace App\Support\Reference\Exceptions;

use RuntimeException;

/**
 * Thrown when a (size, schedule) pair does not exist in the standard.
 *
 * The schedule table is SPARSE ON PURPOSE. 1/2" SCH 10 exists; 1/2" SCH 30 does
 * not. Absence is data, and asking for an absent combination is a bug in the
 * caller, not a zero.
 */
final class UnknownScheduleException extends RuntimeException
{
    /** @param list<string> $available */
    public static function forSize(string $nps, string $schedule, array $available): self
    {
        $list = $available === [] ? 'none' : implode(', ', $available);

        return new self(
            "Schedule '{$schedule}' does not exist for NPS {$nps} in ASME B36.10M. ".
            "Available at this size: {$list}."
        );
    }

    public static function unknownCode(string $schedule): self
    {
        return new self("Unknown schedule code '{$schedule}'.");
    }
}
