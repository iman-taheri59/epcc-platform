<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Punch item categories (SRS FR-COM-04).
 *
 * The category is not a severity label for reporting - it decides which
 * completion milestone the item blocks, which is the only reason the punch
 * list exists as a controlled register rather than a spreadsheet:
 *
 *   A - must be closed before Mechanical Completion. Safety, code compliance,
 *       or anything that prevents the system being energised or pressurised.
 *   B - must be closed before RFSU / handover, but does not stop MC. The plant
 *       can be commissioned around it.
 *   C - cosmetic or documentation; closed during the warranty period.
 *
 * The A/B/C scheme is the common one, but the SRS allows a client scheme, so
 * the labels live here and the BLOCKING RULE lives in blocksMechanicalCompletion().
 * A client who renames A to "Category 1" changes a label; a client who decides
 * B also blocks MC changes a rule. Those are different edits on purpose.
 */
final class PunchCategory
{
    public const A = 'A';
    public const B = 'B';
    public const C = 'C';

    /** @var list<string> */
    public const ALL = [self::A, self::B, self::C];

    /**
     * The single source of truth for the MC gate.
     *
     * Only A blocks. If this ever returns true for B, McCertificateService
     * starts refusing certificates without any other change - which is the
     * point of putting it in one place.
     */
    public static function blocksMechanicalCompletion(string $category): bool
    {
        return $category === self::A;
    }

    /** B items block RFSU (ready for start-up), the milestone after MC. */
    public static function blocksReadyForStartUp(string $category): bool
    {
        return $category === self::A || $category === self::B;
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }

    /**
     * @return array{fa:string,en:string}
     *
     * The CATEGORY LETTER stays Latin A/B/C in both languages. On an EPCC site
     * the punch category is a contractual term that appears in the same form
     * on drawings, in the MC procedure and in correspondence with vendors who
     * do not read Persian; translating it to الف/ب/ج invented a second
     * vocabulary for the same thing. The explanation after the dash is
     * translated, because that part is prose.
     */
    public static function label(string $category): array
    {
        return match ($category) {
            self::A => ['fa' => 'A — مانع تکمیل مکانیکی', 'en' => 'A - blocks Mechanical Completion'],
            self::B => ['fa' => 'B — مانع آماده‌سازی راه‌اندازی', 'en' => 'B - blocks RFSU'],
            self::C => ['fa' => 'C — جزئی / مستندات', 'en' => 'C - minor / documentation'],
            default => ['fa' => $category, 'en' => $category],
        };
    }

    /**
     * The bare letter, identical in both languages - see label() for why.
     *
     * $lang is kept in the signature: callers pass it, and the day a market
     * needs a localised letter this is the one place that changes.
     */
    public static function shortLabel(string $category, string $lang = 'fa'): string
    {
        return match ($category) {
            self::A, self::B, self::C => $category,
            default => $category,
        };
    }
}
