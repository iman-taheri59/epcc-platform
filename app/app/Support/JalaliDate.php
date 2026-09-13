<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * One place that turns a date into the string an Iranian user expects.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every hand-written screen carried its own `$jdate = function ($d) ...`
 * closure - transmittals, punch, documents, the print views. They agreed by
 * luck rather than by construction, and the Filament panel could not reuse any
 * of them, so its first render showed `سپتامبر 12, 2026` on a page where every
 * other date on the product is Jalali. A Gregorian date on a Persian screen is
 * not a cosmetic slip: on a submission log it is the difference between a
 * letter being on time and being late.
 *
 * `format()` takes the language rather than reading the app locale, because
 * the PDF renderer formats a document in a language chosen per-render, not
 * per-request.
 */
final class JalaliDate
{
    /** Date only: ۱۴۰۵/۰۶/۲۱ in Persian, 2026-09-12 in English. */
    public static function format(?DateTimeInterface $date, string $lang = 'fa'): string
    {
        if ($date === null) {
            return '—';
        }

        if ($lang !== 'fa') {
            return $date->format('Y-m-d');
        }

        return CalendarUtils::convertNumbers(
            Jalalian::fromDateTime($date)->format('Y/m/d')
        );
    }

    /** Date and time, for audit-style columns where the hour matters. */
    public static function formatWithTime(?DateTimeInterface $date, string $lang = 'fa'): string
    {
        if ($date === null) {
            return '—';
        }

        if ($lang !== 'fa') {
            return $date->format('Y-m-d H:i');
        }

        return CalendarUtils::convertNumbers(
            Jalalian::fromDateTime($date)->format('Y/m/d H:i')
        );
    }

    /** Persian digits for an already-rendered string (counts, numbers). */
    public static function digits(string $text, string $lang = 'fa'): string
    {
        return $lang === 'fa' ? CalendarUtils::convertNumbers($text) : $text;
    }
}
