<?php

declare(strict_types=1);

namespace App\Support\Reference\Exceptions;

use RuntimeException;

/**
 * Thrown when a flange material (specification / grade) is asked for that the
 * ASME B16.5 flange-ratings reference pack does not assign to a material
 * group. A silent fallback to "Group 1.1 ratings" would rate a stainless or
 * alloy flange as carbon steel — unconditionally unsafe, so we throw.
 */
final class UnknownFlangeMaterialException extends RuntimeException
{
    public static function material(string $spec, ?string $grade): self
    {
        $label = trim("{$spec} " . ($grade ?? ''));

        return new self("Unknown B16.5 flange material '{$label}'. It is not in the reference pack's material index.");
    }
}
