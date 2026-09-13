<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Review comment categories and their consequences (SRS FR-ENG-06).
 *
 * The categories are not decoration. In a real review cycle they are the
 * difference between "fix this before anyone builds it" and "noted, carry on",
 * so each one carries an explicit BLOCKING flag and the publication gate reads
 * that flag rather than hard-coding a list of codes somewhere else.
 *
 *   HOLD    Work must stop. Never waivable.
 *   CODE1   Rejected - resubmit. Blocks.
 *   CODE2   Approved with comments - incorporate and resubmit. Blocks.
 *   CODE3   Approved with minor comments - incorporate, no resubmission.
 *   CODE4   Approved as submitted.
 *   INFO    For information only; carries no obligation.
 *
 * CODE3 does not block publication but still demands a disposition: "we read
 * it and here is what we did" is the whole value of a comment sheet. Only INFO
 * may be left unanswered.
 */
final class CommentCategory
{
    public const HOLD = 'HOLD';
    public const CODE1 = 'CODE1';
    public const CODE2 = 'CODE2';
    public const CODE3 = 'CODE3';
    public const CODE4 = 'CODE4';
    public const INFO = 'INFO';

    /**
     * category => [blocks publication, needs a disposition, en, fa]
     *
     * @var array<string,array{0:bool,1:bool,2:string,3:string}>
     */
    private const CATEGORIES = [
        self::HOLD  => [true,  true,  'Hold - work must stop',            'توقف کار'],
        self::CODE1 => [true,  true,  'Code 1 - rejected, resubmit',      'کد ۱ - مردود، ارسال مجدد'],
        self::CODE2 => [true,  true,  'Code 2 - revise and resubmit',     'کد ۲ - اصلاح و ارسال مجدد'],
        self::CODE3 => [false, true,  'Code 3 - minor comments',          'کد ۳ - اصلاحات جزئی'],
        self::CODE4 => [false, false, 'Code 4 - approved as submitted',   'کد ۴ - تأیید بدون تغییر'],
        self::INFO  => [false, false, 'For information',                  'جهت اطلاع'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function isValid(string $category): bool
    {
        return array_key_exists($category, self::CATEGORIES);
    }

    /**
     * Does an open comment in this category prevent publication?
     *
     * This is the single source of truth for the gate. Adding a category is a
     * one-line change here, not a hunt through controllers.
     */
    public static function blocksPublication(string $category): bool
    {
        return self::CATEGORIES[$category][0] ?? false;
    }

    /** Must the originator answer this comment before the sheet is closed? */
    public static function requiresDisposition(string $category): bool
    {
        return self::CATEGORIES[$category][1] ?? false;
    }

    /** @return list<string> categories that block publication while open */
    public static function blocking(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (string $c): bool => self::blocksPublication($c),
        ));
    }

    public static function label(string $category, string $lang = 'fa'): string
    {
        $row = self::CATEGORIES[$category] ?? null;

        if ($row === null) {
            return $category;
        }

        return $lang === 'fa' ? $row[3] : $row[2];
    }

    /** Short badge text, identical in both languages so tables stay narrow. */
    public static function code(string $category, string $lang = 'fa'): string
    {
        if ($category === self::HOLD) {
            return $lang === 'fa' ? 'توقف' : 'HOLD';
        }

        if ($category === self::INFO) {
            return $lang === 'fa' ? 'اطلاع' : 'INFO';
        }

        $n = substr($category, 4);

        return $lang === 'fa'
            ? 'کد '.\Morilog\Jalali\CalendarUtils::convertNumbers($n)
            : 'C'.$n;
    }
}
