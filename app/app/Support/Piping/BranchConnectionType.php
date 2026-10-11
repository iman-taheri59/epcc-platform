<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * The closed vocabulary a branch-table cell may resolve to (ADR-008 §4.2).
 *
 * STUB_IN is the Pipe-to-Pipe outcome: the branch pipe is contoured and
 * welded straight into the header with no fitting in between. Downstream
 * (ADR-008 build phase 3) that difference is load-bearing — a STUB_IN cell
 * means "expect NO component at this branch", while every other type means
 * the modelled component must match the class.
 *
 * A reducing tee is deliberately NOT its own type (ADR-008 §7 Q2): reduction
 * is derived from the port sizes, never stored, so a stored type cannot
 * contradict its own ports.
 */
final class BranchConnectionType
{
    public const TEE = 'TEE';
    public const WELDOLET = 'WELDOLET';
    public const SOCKOLET = 'SOCKOLET';
    public const STUB_IN = 'STUB_IN';

    /** @var list<string> */
    public const ALL = [
        self::TEE,
        self::WELDOLET,
        self::SOCKOLET,
        self::STUB_IN,
    ];

    /** @return array{fa:string,en:string} */
    public static function label(string $type): array
    {
        return match ($type) {
            self::TEE => ['fa' => 'سه‌راهی', 'en' => 'Tee'],
            self::WELDOLET => ['fa' => 'ولدولت', 'en' => 'Weldolet'],
            self::SOCKOLET => ['fa' => 'ساکولت', 'en' => 'Sockolet'],
            self::STUB_IN => ['fa' => 'لوله به لوله (Stub-in)', 'en' => 'Pipe-to-pipe (stub-in)'],
            default => ['fa' => $type, 'en' => $type],
        };
    }
}
