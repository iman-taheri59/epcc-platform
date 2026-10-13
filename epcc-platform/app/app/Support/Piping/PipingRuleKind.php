<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * The kinds of size-banded rule a PMS states (ADR-008 §3).
 *
 * The user gave one example - "1/2 to 4 inch SCH80, 4 to 10 inch SCH40" - and
 * it is one instance of a general shape: a half-open DN band resolving to a
 * value. End preparation, valve type, flange type, gasket and corrosion
 * allowance are all written the same way in a PMS, so they are one table
 * discriminated by kind rather than six near-identical tables.
 *
 * Each kind declares the key it resolves to, because the payload differs:
 * a schedule rule yields {"schedule":"80"}, an end-preparation rule yields
 * {"end_type":"SW"}. Validating the payload against the kind is what stops a
 * rule that looks stored but resolves to nothing.
 */
final class PipingRuleKind
{
    /** Wall thickness, via a schedule from the ASME reference pack. */
    public const PIPE_SCHEDULE = 'PIPE_SCHEDULE';

    /** Socket weld below some size, butt weld above it. */
    public const END_PREPARATION = 'END_PREPARATION';

    /** Which valve the class wants at this size (ball small, gate large). */
    public const VALVE_TYPE = 'VALVE_TYPE';

    /** Slip-on/socket below some size, weld neck above it. */
    public const FLANGE_TYPE = 'FLANGE_TYPE';

    /** Which gasket family this size band takes. */
    public const GASKET_TYPE = 'GASKET_TYPE';

    // REMOVED 2026-09-24: CORROSION_ALLOWANCE. It was here on the belief that
    // the allowance varies with size. The user was asked directly and answered
    // no: a single class never carries a different corrosion allowance at
    // different sizes. A different allowance is what makes a different class.
    // The value lives in the header as piping_classes.corrosion_allowance_mm.
    // See ADR-008 §11.5.

    /**
     * The jsonb key each kind must carry.
     *
     * @var array<string,string>
     */
    private const VALUE_KEY = [
        self::PIPE_SCHEDULE => 'schedule',
        self::END_PREPARATION => 'end_type',
        self::VALVE_TYPE => 'valve_type',
        self::FLANGE_TYPE => 'flange_type',
        self::GASKET_TYPE => 'gasket_type',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::VALUE_KEY);
    }

    public static function isKnown(string $kind): bool
    {
        return array_key_exists($kind, self::VALUE_KEY);
    }

    /** The jsonb key this kind resolves to, or null if the kind is unknown. */
    public static function valueKey(string $kind): ?string
    {
        return self::VALUE_KEY[$kind] ?? null;
    }

    /** @return array{en:string,fa:string} */
    public static function label(string $kind): array
    {
        return match ($kind) {
            self::PIPE_SCHEDULE => ['en' => 'Pipe schedule', 'fa' => 'رده لوله'],
            self::END_PREPARATION => ['en' => 'End preparation', 'fa' => 'آماده‌سازی انتها'],
            self::VALVE_TYPE => ['en' => 'Valve type', 'fa' => 'نوع شیر'],
            self::FLANGE_TYPE => ['en' => 'Flange type', 'fa' => 'نوع فلنج'],
            self::GASKET_TYPE => ['en' => 'Gasket type', 'fa' => 'نوع واشر'],
            default => ['en' => $kind, 'fa' => $kind],
        };
    }
}
