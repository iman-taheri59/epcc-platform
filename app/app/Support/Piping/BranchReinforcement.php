<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * Reinforcement of a branch connection (ADR-008 branch table).
 *
 * NONE — the run/branch wall carries the opening; no added metal.
 * PAD  — a reinforcement pad is required, and its thickness becomes a
 *        MANDATORY datum (owner rule, 2026-10-11): the database refuses a
 *        PAD cell whose pad thickness is missing.
 */
final class BranchReinforcement
{
    public const NONE = 'NONE';
    public const PAD = 'PAD';

    /** @var list<string> */
    public const ALL = [
        self::NONE,
        self::PAD,
    ];

    /** @return array{fa:string,en:string} */
    public static function label(string $kind): array
    {
        return match ($kind) {
            self::NONE => ['fa' => 'بدون تقویت', 'en' => 'No reinforcement'],
            self::PAD => ['fa' => 'پد تقویتی', 'en' => 'Reinforcement pad'],
            default => ['fa' => $kind, 'en' => $kind],
        };
    }
}
