<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The AFC gate on a piping line (ADR-006 §5.1).
 *
 * WHAT "AFC" MEANS AND WHY IT IS A GATE
 * -------------------------------------
 * Approved For Construction. Until a line is AFC, the drawing it is built from
 * is still moving: a P&ID comment, a stress re-run or a vendor print can change
 * the routing, the class or the size. Material bought against a non-AFC line is
 * a bet, and a weld made against one is rework waiting to be discovered.
 *
 * So this is not decoration on a register - it is the point where the office
 * tells the site "this is safe to build". The states are deliberately few:
 *
 *   DRAFT          engineering is still working. Freely editable.
 *   AFC_CONFIRMED  the evidence is attached and checked - the isometric and
 *                  P&ID are at an approved revision in the CDE. The line is
 *                  frozen for construction intent but not yet issued.
 *   RELEASED       issued to the field. This is what a fitter may build from.
 *
 * WHY TWO STATES RATHER THAN ONE AFC FLAG
 * ---------------------------------------
 * Because confirming the evidence and releasing to site are done by different
 * people at different moments, and conflating them loses the more useful of the
 * two. "Documents are approved" is an engineering fact; "the field may start"
 * is a management decision that also depends on material, access and the work
 * pack. A single boolean forces the engineer's answer to double as the
 * manager's, and then nobody can tell why a line is not being built.
 *
 * REVERSIBILITY IS NOT SYMMETRIC
 * ------------------------------
 * A line can be pulled back from RELEASED - a hold, a design change, a stop
 * work - and that must be possible without a database administrator, because
 * it happens on real jobs and the alternative is people building from a line
 * everyone knows is wrong. But going backwards is a deliberate act with a
 * reason attached, not a toggle: see PipingLineService::revertRelease().
 *
 * What is NOT here: nothing is deleted and no state means "cancelled". A line
 * that should not be built is a design change and gets a revision, exactly as
 * ADR-013 concluded for projects.
 */
final class LineReleaseState
{
    /** Engineering in progress. The line may be edited freely. */
    public const DRAFT = 'DRAFT';

    /** AFC evidence attached and verified. Construction intent is frozen. */
    public const AFC_CONFIRMED = 'AFC_CONFIRMED';

    /** Issued to the field. This is what may be built. */
    public const RELEASED = 'RELEASED';

    /**
     * Allowed moves. Forward one step at a time; backward from either
     * committed state to DRAFT, which is how a hold or a design change is
     * recorded.
     *
     * RELEASED -> AFC_CONFIRMED is deliberately absent: stepping back half a
     * level says nothing useful. If construction must stop, the line goes back
     * to DRAFT with a reason and re-earns both steps.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::DRAFT => [self::AFC_CONFIRMED],
        self::AFC_CONFIRMED => [self::RELEASED, self::DRAFT],
        self::RELEASED => [self::DRAFT],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::AFC_CONFIRMED, self::RELEASED];
    }

    public static function isKnown(string $state): bool
    {
        return in_array($state, self::all(), true);
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

    /**
     * May the field build this line?
     *
     * The single question the whole state machine exists to answer, kept as one
     * named method so no caller has to remember which states qualify. Note it
     * is NOT "is it approved" - AFC_CONFIRMED is approved and still not
     * buildable.
     */
    public static function isBuildable(string $state): bool
    {
        return $state === self::RELEASED;
    }

    /**
     * Is the line still freely editable?
     *
     * Once AFC evidence is confirmed, changing the size or class of a line
     * silently invalidates the very documents that were checked. Edits are
     * still possible - engineering changes are normal - but they must go
     * through a step back to DRAFT so the confirmation is re-earned.
     */
    public static function isEditable(string $state): bool
    {
        return $state === self::DRAFT;
    }

    /** Has this line passed the AFC gate, whether or not it is released yet? */
    public static function isAfc(string $state): bool
    {
        return $state === self::AFC_CONFIRMED || $state === self::RELEASED;
    }

    /**
     * Ordinal for sorting and progress roll-ups.
     *
     * Deliberately not stored: it is presentation, and a stored ordinal drifts
     * the moment a state is inserted in the middle.
     */
    public static function ordinalOf(string $state): int
    {
        return match ($state) {
            self::DRAFT => 0,
            self::AFC_CONFIRMED => 1,
            self::RELEASED => 2,
            default => -1,
        };
    }

    /** @return array{en: string, fa: string} */
    public static function label(string $state): array
    {
        return match ($state) {
            self::DRAFT => ['en' => 'Draft', 'fa' => 'پیش‌نویس'],
            self::AFC_CONFIRMED => ['en' => 'AFC confirmed', 'fa' => 'تأیید AFC'],
            self::RELEASED => ['en' => 'Released for construction', 'fa' => 'ابلاغ برای اجرا'],
            default => ['en' => $state, 'fa' => $state],
        };
    }
}
