<?php

declare(strict_types=1);

namespace App\Support\Itp;

/**
 * The intervention level a party holds at one ITP stage.
 *
 * This is the "Inspection Code" column of a real ITP. The vocabulary is
 * industry-standard and stable across clients: H, W, M/S, R. Some clients add
 * A (approve document) and RE (records only); those are folded into R and
 * REVIEW respectively rather than multiplied, because a party that reviews a
 * document and a party that approves it differ by AUTHORITY, not by activity,
 * and authority already lives in the role model.
 *
 * WHY THIS IS NOT A BOOLEAN "inspection required"
 * -----------------------------------------------
 * The whole commercial weight of an ITP is in the difference between H and W.
 * At a HOLD the vendor may not proceed until the party attends and releases.
 * At a WITNESS the party must be INVITED and given the agreed notice, but if
 * they do not come the work proceeds lawfully. That distinction decides who
 * pays for a week of standby, and it is argued over in real claims. A single
 * "requires inspection" flag erases exactly the fact that matters.
 */
final class InterventionCode
{
    /**
     * HOLD - نقطهٔ توقف.
     *
     * Work stops. The stage cannot be passed until this party attends and
     * signs. Reserved for the few steps that are irreversible or safety- and
     * code-critical, because an ITP with forty hold points is an ITP that gets
     * waived informally on site.
     */
    public const HOLD = 'H';

    /**
     * WITNESS - نقطهٔ شاهد.
     *
     * The party must be notified and given the chance to attend. If they do
     * not attend within the notice period, work may proceed - but the
     * invitation must be evidenced, which is why a witness stage that was
     * passed without attendance records WHY (see StageResult::WAIVED).
     */
    public const WITNESS = 'W';

    /**
     * MONITOR / SURVEILLANCE - پایش.
     *
     * The party may drop in unannounced. No notification is owed, nothing
     * waits. Recorded so the ITP is complete, not because it gates anything.
     */
    public const MONITOR = 'M';

    /**
     * REVIEW - بررسی مدرک.
     *
     * Document or record review only: MTC, NDT report, calibration
     * certificate. No physical attendance. Note this is still a real gate when
     * combined with HOLD-like criticality - an MTC review that fails must be
     * able to stop material, which is why the gating question is asked of the
     * STAGE, not of this code alone.
     */
    public const REVIEW = 'R';

    /** The party has no involvement at this stage. Stored as absence, never a row. */
    public const NONE = '-';

    /** @var list<string> */
    public const ALL = [self::HOLD, self::WITNESS, self::MONITOR, self::REVIEW];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }

    /**
     * Does this code make the party's signature mandatory before the stage
     * can be passed?
     *
     * Only HOLD. Everything else is either advisory (M), notice-based (W) or
     * documentary (R). This single predicate is the entire enforcement
     * surface, deliberately: one place to read, one place to change if a
     * client argues that their W behaves like an H.
     */
    public static function blocks(string $code): bool
    {
        return $code === self::HOLD;
    }

    /** Does the party have to be invited before the activity happens? */
    public static function requiresNotice(string $code): bool
    {
        return $code === self::HOLD || $code === self::WITNESS;
    }

    /** @return array{fa:string,en:string} */
    public static function label(string $code): array
    {
        return match ($code) {
            self::HOLD => ['fa' => 'توقف', 'en' => 'Hold'],
            self::WITNESS => ['fa' => 'شاهد', 'en' => 'Witness'],
            self::MONITOR => ['fa' => 'پایش', 'en' => 'Monitor'],
            self::REVIEW => ['fa' => 'بررسی مدرک', 'en' => 'Review'],
            self::NONE => ['fa' => 'بدون دخالت', 'en' => 'No involvement'],
            default => ['fa' => $code, 'en' => $code],
        };
    }

    /** @return array{fa:string,en:string} */
    public static function description(string $code): array
    {
        return match ($code) {
            self::HOLD => [
                'fa' => 'کار متوقف می‌ماند تا این رکن حاضر شود و امضا کند.',
                'en' => 'Work stops until this party attends and signs.',
            ],
            self::WITNESS => [
                'fa' => 'دعوت الزامی است؛ اگر در مهلت مقرر حاضر نشود، کار ادامه می‌یابد.',
                'en' => 'The invitation is mandatory; if they do not attend within the notice period, work proceeds.',
            ],
            self::MONITOR => [
                'fa' => 'می‌تواند سرزده حاضر شود؛ چیزی منتظر او نمی‌ماند.',
                'en' => 'May attend unannounced; nothing waits for them.',
            ],
            self::REVIEW => [
                'fa' => 'فقط مدرک و سوابق را بررسی می‌کند؛ حضور فیزیکی لازم نیست.',
                'en' => 'Reviews documents and records only; no physical attendance.',
            ],
            default => ['fa' => '', 'en' => ''],
        };
    }
}
