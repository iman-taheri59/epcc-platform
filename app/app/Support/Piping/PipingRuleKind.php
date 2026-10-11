<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * ADR-008 §3: one rule table, discriminated by kind. "½–3½ SCH 80, 4–10
 * SCH 40" and "≤2\" ball valve, >2\" gate valve" are the same shape — a DN
 * band resolving to a value — so they share one table and one resolver.
 */
final class PipingRuleKind
{
    public const PIPE_SCHEDULE = 'PIPE_SCHEDULE';
    public const END_PREPARATION = 'END_PREPARATION';
    public const VALVE_TYPE = 'VALVE_TYPE';
    public const FLANGE_TYPE = 'FLANGE_TYPE';
    public const GASKET_TYPE = 'GASKET_TYPE';
    public const BOLT_SPEC = 'BOLT_SPEC';
    public const CORROSION_ALLOWANCE = 'CORROSION_ALLOWANCE';

    /** @var list<string> */
    public const ALL = [
        self::PIPE_SCHEDULE,
        self::END_PREPARATION,
        self::VALVE_TYPE,
        self::FLANGE_TYPE,
        self::GASKET_TYPE,
        self::BOLT_SPEC,
        self::CORROSION_ALLOWANCE,
    ];

    /** @return array{fa:string,en:string} */
    public static function label(string $kind): array
    {
        return match ($kind) {
            self::PIPE_SCHEDULE => ['fa' => 'ضخامت/اسکجول لوله', 'en' => 'Pipe schedule'],
            self::END_PREPARATION => ['fa' => 'نوع آماده‌سازی انتها', 'en' => 'End preparation'],
            self::VALVE_TYPE => ['fa' => 'نوع شیر', 'en' => 'Valve type'],
            self::FLANGE_TYPE => ['fa' => 'نوع فلنج', 'en' => 'Flange type'],
            self::GASKET_TYPE => ['fa' => 'نوع گسکت', 'en' => 'Gasket type'],
            self::BOLT_SPEC => ['fa' => 'مشخصهٔ پیچ و مهره', 'en' => 'Bolt specification'],
            self::CORROSION_ALLOWANCE => ['fa' => 'اضافه‌ضخامت خوردگی', 'en' => 'Corrosion allowance'],
            default => ['fa' => $kind, 'en' => $kind],
        };
    }
}
