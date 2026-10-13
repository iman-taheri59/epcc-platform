<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Project lifecycle (ADR-013 §7.2).
 *
 *     ACTIVE <-> ARCHIVED
 *
 * ARCHIVED means read-only and out of the way: hidden from pickers, excluded
 * from plan quotas, absent from dashboards - but fully readable, fully
 * exportable, and reversible.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * Deleting PH-14 in the ADR-013 spike destroyed 1 MC certificate, 2 payment
 * certificates, 5 transmittals and 3 material inspections. Those are the
 * documents a company needs when a claim arrives two years after handover.
 * "Finished" and "gone" are different things, and conflating them is how an
 * EPCC contractor loses a dispute.
 *
 * WHY THERE IS NO 'DELETED' STATE HERE
 * ------------------------------------
 * Hard deletion is deliberately NOT a state in this machine. It is a separate,
 * ceremonial operation (ADR-013 §7.3) requiring a verified export, a second
 * approver and a retention-log entry that outlives the project. Making it a
 * transition would put it one dropdown away from archiving, which is exactly
 * the accident this design exists to prevent.
 */
final class ProjectState
{
    public const ACTIVE = 'ACTIVE';
    public const ARCHIVED = 'ARCHIVED';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        // Reversible on purpose. A project archived by mistake, or reopened for
        // a warranty claim, must not need a database administrator.
        self::ACTIVE => [self::ARCHIVED],
        self::ARCHIVED => [self::ACTIVE],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::ARCHIVED];
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
    public static function nextStates(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * Is the project writable in this state?
     *
     * The single source of truth for the question. Services ask this rather
     * than comparing strings, so adding a future state (SUSPENDED, say) cannot
     * accidentally become writable by omission.
     */
    public static function isWritable(string $state): bool
    {
        return $state === self::ACTIVE;
    }

    /**
     * Does this state count against plans.max_projects?
     *
     * An archived project must not consume a licence slot - otherwise a
     * customer on the starter plan (max_projects = 1) can never finish one
     * project and start another without destroying the first, which would push
     * them toward deletion precisely when they should be archiving.
     */
    public static function countsAgainstQuota(string $state): bool
    {
        return $state === self::ACTIVE;
    }

    public static function labelKey(string $state): string
    {
        return 'project.state.'.strtolower($state);
    }
}
