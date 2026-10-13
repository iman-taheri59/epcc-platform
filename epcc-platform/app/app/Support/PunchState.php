<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Punch item lifecycle (SRS FR-COM-04: raise -> assign -> fix -> verify -> close).
 *
 *     RAISED -> ASSIGNED -> FIXED -> VERIFIED -> CLOSED
 *                              \-> REJECTED -> ASSIGNED
 *
 * The important property is that FIXED is NOT closed. The contractor says it
 * fixed the item; somebody independent has to agree. Collapsing those two into
 * one "done" flag is how a punch list stops being evidence - and the MC
 * certificate is signed on the strength of this register.
 *
 * Per the SRS visibility matrix (§4.1, "Punch item (A)"):
 *   MC/TPI raise, EPC fixes, PMC/MC verify, Owner approves the close.
 * Those are ROLE rules and live in PunchListService; this class only owns which
 * transitions exist at all.
 */
final class PunchState
{
    public const RAISED = 'RAISED';
    public const ASSIGNED = 'ASSIGNED';
    public const FIXED = 'FIXED';
    public const VERIFIED = 'VERIFIED';
    public const CLOSED = 'CLOSED';
    public const REJECTED = 'REJECTED';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::RAISED => [self::ASSIGNED],
        self::ASSIGNED => [self::FIXED],
        // Verification can go either way. Rejection is a first-class outcome,
        // not an error: "you said you fixed it, you did not".
        self::FIXED => [self::VERIFIED, self::REJECTED],
        self::VERIFIED => [self::CLOSED],
        // A rejected item goes back to the contractor, it does not die.
        self::REJECTED => [self::ASSIGNED],
        self::CLOSED => [],
    ];

    /**
     * An item stops counting against a completion gate only when CLOSED.
     *
     * VERIFIED is deliberately excluded. Verified means the inspector agrees the
     * work is done; closed means the owner's representative accepted it into the
     * record. On a real project those are days apart, and the gate follows the
     * record, not the opinion.
     */
    public static function isOpen(string $state): bool
    {
        return $state !== self::CLOSED;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function nextStates(string $state): array
    {
        return self::TRANSITIONS[$state] ?? [];
    }

    public static function isValid(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    /** @return array{fa:string,en:string} */
    public static function label(string $state): array
    {
        return match ($state) {
            self::RAISED => ['fa' => 'ثبت‌شده', 'en' => 'Raised'],
            self::ASSIGNED => ['fa' => 'ارجاع‌شده', 'en' => 'Assigned'],
            self::FIXED => ['fa' => 'رفع‌شده (اعلام پیمانکار)', 'en' => 'Fixed (contractor says)'],
            self::VERIFIED => ['fa' => 'تأیید بازرسی', 'en' => 'Verified'],
            self::CLOSED => ['fa' => 'بسته‌شده', 'en' => 'Closed'],
            self::REJECTED => ['fa' => 'رد شده', 'en' => 'Rejected'],
            default => ['fa' => $state, 'en' => $state],
        };
    }

    /**
     * Sort order for the register. Sorting by the rendered label is wrong in
     * both languages, so the table carries this as data-sortkey.
     */
    public static function ordinalOf(string $state): int
    {
        return match ($state) {
            self::RAISED => 0,
            self::ASSIGNED => 1,
            self::REJECTED => 2,
            self::FIXED => 3,
            self::VERIFIED => 4,
            self::CLOSED => 5,
            default => 99,
        };
    }
}
