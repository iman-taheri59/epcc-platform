<?php

declare(strict_types=1);

namespace App\Support\Reference\Exceptions;

use RuntimeException;

/**
 * Thrown when a size is asked for that ASME does not define.
 *
 * This THROWS rather than returning null on purpose (ADR-007 section 5): a
 * silent null becomes a silent zero becomes a wall-thickness check that passes
 * everything.
 */
final class UnknownSizeException extends RuntimeException
{
    public static function nps(string $nps): self
    {
        return new self("Unknown pipe size NPS '{$nps}'. It is not defined in the reference pack.");
    }

    public static function dn(int $dn): self
    {
        return new self("Unknown pipe size DN{$dn}. It is not defined in the reference pack.");
    }
}
