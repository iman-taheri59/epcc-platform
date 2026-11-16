<?php

declare(strict_types=1);

namespace App\Services;

/** Backward-compatible PIPE-specific facade for the straight profile resolver. */
final class StraightPipeGeometryProfile
{
    public const PROFILE_ID = LinearTwoPortComponentGeometryProfile::PIPE_PROFILE_ID;

    /** @return array<string, mixed> */
    public static function resolve(?float $lengthMm): array
    {
        return LinearTwoPortComponentGeometryProfile::resolve('PIPE', $lengthMm);
    }
}
