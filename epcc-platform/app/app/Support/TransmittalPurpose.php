<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Why a transmittal was sent (SRS FR-ENG-05).
 *
 * The purpose is not a label - it decides whether an acknowledgement is
 * EXPECTED and how long the recipient has. "For construction" with no
 * acknowledgement is the single most expensive gap in a delay claim, so it
 * gets the shortest clock; "for information" carries no obligation at all and
 * must not generate overdue noise that trains people to ignore the list.
 */
final class TransmittalPurpose
{
    public const FOR_REVIEW = 'FOR_REVIEW';
    public const FOR_APPROVAL = 'FOR_APPROVAL';
    public const FOR_CONSTRUCTION = 'FOR_CONSTRUCTION';
    public const FOR_INFORMATION = 'FOR_INFORMATION';

    /**
     * purpose => [needs acknowledgement, default days, en, fa]
     *
     * @var array<string,array{0:bool,1:int,2:string,3:string}>
     */
    private const PURPOSES = [
        self::FOR_REVIEW       => [true,  14, 'For review',       'جهت بررسی'],
        self::FOR_APPROVAL     => [true,  10, 'For approval',     'جهت تصویب'],
        self::FOR_CONSTRUCTION => [true,   7, 'For construction', 'جهت اجرا'],
        self::FOR_INFORMATION  => [false,  0, 'For information',  'جهت اطلاع'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::PURPOSES);
    }

    public static function isValid(string $purpose): bool
    {
        return array_key_exists($purpose, self::PURPOSES);
    }

    public static function requiresAcknowledgement(string $purpose): bool
    {
        return self::PURPOSES[$purpose][0] ?? false;
    }

    /** Days allowed before the acknowledgement is overdue. */
    public static function defaultDays(string $purpose): int
    {
        return self::PURPOSES[$purpose][1] ?? 0;
    }

    public static function label(string $purpose, string $lang = 'fa'): string
    {
        $row = self::PURPOSES[$purpose] ?? null;

        if ($row === null) {
            return $purpose;
        }

        return $lang === 'fa' ? $row[3] : $row[2];
    }
}
