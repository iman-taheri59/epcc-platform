<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * The two kinds of Pipe-to-Pipe branch (owner decision, 2026-10-11):
 *
 * - STUB_IN: the branch end is contoured and seats INSIDE the hole cut in
 *   the header — the usual small-branch form.
 * - STUB_ON: the branch sits ON the header surface, saddle-contoured
 *   (زینی), without entering the bore.
 *
 * The kind is required on P2P cells and forbidden on fitting cells, which
 * the database enforces with two CHECKs.
 */
final class P2pKind
{
    public const STUB_IN = 'STUB_IN';
    public const STUB_ON = 'STUB_ON';

    /** @var list<string> */
    public const ALL = [
        self::STUB_IN,
        self::STUB_ON,
    ];

    /** @return array{fa:string,en:string} */
    public static function label(string $kind): array
    {
        return match ($kind) {
            self::STUB_IN => ['fa' => 'Stub-in (داخل سوراخ هدر)', 'en' => 'Stub-in (into the header bore)'],
            self::STUB_ON => ['fa' => 'Stub-on (زینی روی هدر)', 'en' => 'Stub-on (saddle on the header)'],
            default => ['fa' => $kind, 'en' => $kind],
        };
    }
}
