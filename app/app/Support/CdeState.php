<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ISO 19650 CDE states and the transitions between them (SRS FR-ENG-03).
 *
 *     WIP -> SHARED -> PUBLISHED -> ARCHIVED
 *
 * Modelled as an explicit machine rather than a free string column because the
 * illegal transitions are the whole point:
 *
 *   - WIP -> PUBLISHED skips review. A drawing that reaches construction
 *     without ever being shared for check is precisely the failure ISO 19650
 *     exists to prevent.
 *   - ARCHIVED is terminal. Re-opening an archived revision rewrites history;
 *     the correct action is to issue a NEW revision.
 *
 * Suitability codes are carried alongside the state, not derived from it: the
 * S/A/B families mean different things to different clients, so the pack (and
 * ultimately the client's own scheme) decides which are offered.
 */
final class CdeState
{
    public const WIP = 'WIP';
    public const SHARED = 'SHARED';
    public const PUBLISHED = 'PUBLISHED';
    public const ARCHIVED = 'ARCHIVED';

    /** @var array<string,list<string>> state => states reachable from it */
    private const TRANSITIONS = [
        self::WIP => [self::SHARED],
        // Back to WIP is legal and common: review rejected it.
        self::SHARED => [self::PUBLISHED, self::WIP],
        // Published work can only be superseded or archived, never edited.
        self::PUBLISHED => [self::ARCHIVED],
        self::ARCHIVED => [],
    ];

    /**
     * Suitability codes per state (ISO 19650-2 table).
     *
     * WIP has none by design - a work-in-progress container carries no
     * suitability until it is shared.
     *
     * @var array<string,list<string>>
     */
    private const SUITABILITY = [
        self::WIP => [],
        self::SHARED => ['S0', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7'],
        self::PUBLISHED => ['A1', 'A2', 'A3', 'A4', 'A5', 'B1', 'B2', 'B3', 'B4', 'B5'],
        self::ARCHIVED => ['A1', 'A2', 'A3', 'A4', 'A5', 'B1', 'B2', 'B3', 'B4', 'B5'],
    ];

    /**
     * Is this revision fit to build from? (ISO 19650-2)
     *
     * Two conditions, and BOTH are needed - which is the whole point of this
     * method existing rather than a `state === PUBLISHED` check scattered
     * around the codebase.
     *
     *   1. The container must be PUBLISHED. A SHARED revision is issued for
     *      review or comment, not for construction.
     *   2. The suitability code must be in the **A family**. This is the part
     *      that is easy to get wrong: A-codes mean "authorised for
     *      construction"; B-codes mean "partial sign-off - approved WITH
     *      COMMENTS". A B1 drawing is published, it is legitimate, and it is
     *      explicitly NOT fit to build from until the comments are cleared.
     *
     * Treating PUBLISHED alone as approval would wave every B-coded drawing
     * through the AFC gate, which is precisely the mistake that produces
     * rework: the field builds from a revision the reviewer had reservations
     * about, and nobody notices until the comment resurfaces at handover.
     *
     * A revision with no suitability code is not approved either. Absence of a
     * code is absence of a decision.
     */
    public static function isApproved(string $state, ?string $suitability): bool
    {
        if ($state !== self::PUBLISHED) {
            return false;
        }

        if ($suitability === null || $suitability === '') {
            return false;
        }

        return str_starts_with(strtoupper($suitability), 'A');
    }

    /**
     * Position in the lifecycle, for ORDERING.
     *
     * Exists because sorting a state column by its rendered label is wrong in
     * both languages: alphabetical order interleaves the pipeline
     * meaninglessly ("Archived" before "WIP" in English; the Persian labels
     * collate differently again). The register is read as a pipeline, so it
     * must sort as one.
     *
     * An unknown state sorts last rather than throwing: a sort must never be
     * the thing that takes a page down.
     */
    public static function ordinalOf(string $state): int
    {
        $i = array_search($state, self::all(), true);

        return $i === false ? 99 : $i;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::WIP, self::SHARED, self::PUBLISHED, self::ARCHIVED];
    }

    /** @return list<string> */
    public static function nextFrom(string $state): array
    {
        return self::TRANSITIONS[$state] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::nextFrom($from), true);
    }

    /** @return list<string> */
    public static function suitabilityFor(string $state): array
    {
        return self::SUITABILITY[$state] ?? [];
    }

    public static function allowsSuitability(string $state, ?string $code): bool
    {
        if ($code === null || $code === '') {
            // Only WIP may carry no code; anything shared onward must say what
            // it is suitable for, or the recipient cannot know how to use it.
            return $state === self::WIP;
        }

        return in_array($code, self::suitabilityFor($state), true);
    }

    public static function isTerminal(string $state): bool
    {
        return self::nextFrom($state) === [];
    }

    /** Bilingual label (SRS FR-I18N-01). */
    public static function label(string $state, string $lang): string
    {
        $labels = [
            self::WIP => ['en' => 'Work in progress', 'fa' => 'در دست تهیه'],
            self::SHARED => ['en' => 'Shared for review', 'fa' => 'ارسال برای بررسی'],
            self::PUBLISHED => ['en' => 'Published', 'fa' => 'منتشر شده'],
            self::ARCHIVED => ['en' => 'Archived', 'fa' => 'بایگانی شده'],
        ];

        return $labels[$state][$lang] ?? $state;
    }
}
