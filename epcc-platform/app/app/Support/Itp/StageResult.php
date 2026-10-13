<?php

declare(strict_types=1);

namespace App\Support\Itp;

/**
 * The outcome of one ITP stage against one thing being inspected.
 *
 * WAIVED is the interesting one and the reason this is not a boolean.
 *
 * A witness point that the invited party did not attend is NOT "passed" and it
 * is NOT "failed": the work lawfully proceeded without them. Recording that as
 * PASSED is a quiet lie that becomes expensive in a claim, because the record
 * then asserts an attendance that never happened. Recording it as FAILED stops
 * material that nothing is wrong with. So it gets its own state, and it is the
 * only state that demands the notice evidence be named.
 */
final class StageResult
{
    /** Not reached yet. The default for every stage of a fresh record. */
    public const PENDING = 'PENDING';

    /** The party was invited; the clock is running. Only meaningful for H and W. */
    public const NOTIFIED = 'NOTIFIED';

    public const PASSED = 'PASSED';

    public const FAILED = 'FAILED';

    /**
     * Accepted with a recorded reservation.
     *
     * Extremely common and always contentious: the flange is 2 mm out of
     * tolerance and the project takes it anyway. Needs a reason and a name,
     * and must remain visibly different from a clean pass for ever.
     */
    public const CONDITIONAL = 'CONDITIONAL';

    /**
     * The party did not attend a WITNESS point within the notice period and
     * work proceeded lawfully.
     *
     * Not available for a HOLD: a hold that nobody attended is simply still
     * pending, for ever if necessary. That asymmetry IS the difference between
     * the two codes.
     */
    public const WAIVED = 'WAIVED';

    /** Does not apply to this particular item. Recorded rather than deleted. */
    public const NOT_APPLICABLE = 'N_A';

    /** @var list<string> */
    public const ALL = [
        self::PENDING, self::NOTIFIED, self::PASSED, self::FAILED,
        self::CONDITIONAL, self::WAIVED, self::NOT_APPLICABLE,
    ];

    /** @var list<string> */
    public const OPEN = [self::PENDING, self::NOTIFIED];

    /**
     * Does this outcome let the material move on?
     *
     * CONDITIONAL does - that is what "accepted with reservation" means, and a
     * system that blocks it will be bypassed on paper within a week.
     * WAIVED does, because the work was lawful.
     * FAILED does not, and neither does an outcome nobody has recorded.
     */
    public static function satisfied(string $result): bool
    {
        return in_array($result, [
            self::PASSED, self::CONDITIONAL, self::WAIVED, self::NOT_APPLICABLE,
        ], true);
    }

    public static function isOpen(string $result): bool
    {
        return in_array($result, self::OPEN, true);
    }

    /** Outcomes that must carry a written reason. */
    public static function requiresReason(string $result): bool
    {
        return in_array($result, [self::FAILED, self::CONDITIONAL, self::WAIVED, self::NOT_APPLICABLE], true);
    }

    /** @return array{fa:string,en:string} */
    public static function label(string $result): array
    {
        return match ($result) {
            self::PENDING => ['fa' => 'در انتظار', 'en' => 'Pending'],
            self::NOTIFIED => ['fa' => 'دعوت شده', 'en' => 'Notified'],
            self::PASSED => ['fa' => 'قبول', 'en' => 'Passed'],
            self::FAILED => ['fa' => 'مردود', 'en' => 'Failed'],
            self::CONDITIONAL => ['fa' => 'قبول مشروط', 'en' => 'Conditional'],
            self::WAIVED => ['fa' => 'عدم حضور - کار ادامه یافت', 'en' => 'Not attended — work proceeded'],
            self::NOT_APPLICABLE => ['fa' => 'موضوعیت ندارد', 'en' => 'Not applicable'],
            default => ['fa' => $result, 'en' => $result],
        };
    }
}
