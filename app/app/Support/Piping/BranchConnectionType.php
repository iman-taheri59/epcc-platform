<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * The closed vocabulary a branch-table cell may resolve to (ADR-008 §4.2).
 *
 * P2P is the Pipe-to-Pipe outcome (owner terminology, 2026-10-11): the
 * branch pipe is contoured and welded onto/into the header with NO fitting
 * in between. It comes in two kinds — see P2pKind — and is load-bearing for
 * ADR-008 build phase 3: a P2P cell means "expect NO component at this
 * branch", while every other type means the modelled component must match
 * the class.
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
    public const P2P = 'P2P';

    /** @var list<string> */
    public const ALL = [
        self::TEE,
        self::WELDOLET,
        self::SOCKOLET,
        self::P2P,
    ];

    /** @return array{fa:string,en:string} */
    public static function label(string $type): array
    {
        return match ($type) {
            self::TEE => ['fa' => 'سه‌راهی', 'en' => 'Tee'],
            self::WELDOLET => ['fa' => 'ولدولت', 'en' => 'Weldolet'],
            self::SOCKOLET => ['fa' => 'ساکولت', 'en' => 'Sockolet'],
            self::P2P => ['fa' => 'لوله به لوله (P2P)', 'en' => 'Pipe-to-pipe (P2P)'],
            default => ['fa' => $type, 'en' => $type],
        };
    }
}
