<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How the originator answered a review comment (SRS FR-ENG-06).
 *
 * ACCEPTED / REJECTED / NOTED, plus the implicit PENDING for anything not yet
 * answered. A rejection MUST carry a justification: "rejected" with no reason
 * is how a review cycle turns into a dispute, and on an EPCC contract that
 * dispute is eventually read by a lawyer.
 */
final class CommentDisposition
{
    public const PENDING = 'PENDING';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';
    public const NOTED = 'NOTED';

    /** @var array<string,array{0:string,1:string}> */
    private const LABELS = [
        self::PENDING  => ['Pending',  'در انتظار پاسخ'],
        self::ACCEPTED => ['Accepted', 'پذیرفته شد'],
        self::REJECTED => ['Rejected', 'رد شد'],
        self::NOTED    => ['Noted',    'ملاحظه شد'],
    ];

    /** @return list<string> the answers a user may actually choose */
    public static function selectable(): array
    {
        return [self::ACCEPTED, self::REJECTED, self::NOTED];
    }

    public static function isSelectable(string $d): bool
    {
        return in_array($d, self::selectable(), true);
    }

    /**
     * Rejecting someone else's review comment without saying why is not a
     * disposition, it is a shrug.
     */
    public static function requiresJustification(string $d): bool
    {
        return $d === self::REJECTED;
    }

    public static function label(string $d, string $lang = 'fa'): string
    {
        $row = self::LABELS[$d] ?? null;

        if ($row === null) {
            return $d;
        }

        return $lang === 'fa' ? $row[1] : $row[0];
    }
}
