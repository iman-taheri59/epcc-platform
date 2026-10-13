<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The kind of a physical facility inside a project.
 *
 * Why a discriminator and not one table per kind (ADR-004 §3.3):
 *
 * A plant is bounded by coordinates; a pipeline is bounded by stationing
 * (KP 0+000 .. KP 147+500). Two sibling tables would force every upward query -
 * the MC gate, the punch register, the material shortage report - into a
 * two-branch COALESCE, plus an XOR CHECK constraint to stop a subsystem
 * belonging to both. That constraint is the classic smell of a missing
 * abstraction.
 *
 * What the kinds genuinely share is being a *commissioning parent*: something
 * that owns subsystems, gets certificates issued against it and has material
 * allocated to it. What differs is *spatial addressing*, which is exactly what
 * a per-kind `addressing` jsonb column is for.
 *
 * Same pattern this codebase has already proved twice: McCertificate carries a
 * `state` column rather than living in separate tables, and MovementType
 * replaces what would otherwise have been four document tables.
 *
 * PIPELINE is declared here but deliberately NOT yet enabled for creation - see
 * `selectable()`. The user deferred pipelines to a later phase, and a kind that
 * no screen can produce must not be silently creatable through a service.
 */
final class FacilityKind
{
    /** A process plant, refinery unit, station or similar bounded-by-area site. */
    public const PLANT = 'PLANT';

    /** A cross-country pipeline, addressed by stationing. Deferred - see selectable(). */
    public const PIPELINE = 'PIPELINE';

    /** Tank farms, flare areas, roads - inside the fence but outside a process unit. */
    public const OFFSITE = 'OFFSITE';

    /** Jetty, SPM, marine loading. */
    public const MARINE = 'MARINE';

    /** Power, water, air - shared services serving several plants. */
    public const UTILITY = 'UTILITY';

    /**
     * Every kind the column will accept.
     *
     * The CHECK constraint in the migration is generated from this list, so a
     * new kind cannot be stored without appearing here first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::PLANT, self::PIPELINE, self::OFFSITE, self::MARINE, self::UTILITY];
    }

    /**
     * The kinds a user may actually create today.
     *
     * PIPELINE is excluded on the user's instruction ("pipeline را فعلا بگذار
     * برای فازهای بعد"). It stays in `all()` so the column, the CHECK and the
     * labels are ready, and so enabling it later is a one-line change plus its
     * own tests - but nothing can create one in the meantime.
     *
     * This is deliberately NOT enforced by the database: the constraint is a
     * product decision, not a data-integrity one, and a later migration to
     * widen a CHECK is more disruptive than a guard in the service.
     *
     * @return list<string>
     */
    public static function selectable(): array
    {
        return [self::PLANT, self::OFFSITE, self::MARINE, self::UTILITY];
    }

    public static function isValid(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::all(), true);
    }

    public static function isSelectable(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::selectable(), true);
    }

    /**
     * Does this kind describe its extent by stationing rather than by area?
     *
     * Drives which `addressing` keys are meaningful. Deferred for now (the user
     * chose "KP later"), but the predicate is the thing the rest of the code
     * should ask, so that enabling KP does not mean hunting for string
     * comparisons against 'PIPELINE' scattered through the codebase.
     */
    public static function requiresStationing(?string $kind): bool
    {
        return $kind === self::PIPELINE;
    }

    /** @return array{en:string,fa:string} */
    public static function labels(string $kind): array
    {
        return match ($kind) {
            self::PLANT => ['en' => 'Plant', 'fa' => 'واحد'],
            self::PIPELINE => ['en' => 'Pipeline', 'fa' => 'خط لوله'],
            self::OFFSITE => ['en' => 'Offsite', 'fa' => 'محوطه جانبی'],
            self::MARINE => ['en' => 'Marine', 'fa' => 'تأسیسات دریایی'],
            self::UTILITY => ['en' => 'Utility', 'fa' => 'سرویس‌های جانبی'],
            default => ['en' => $kind, 'fa' => $kind],
        };
    }

    public static function label(string $kind, string $lang = 'fa'): string
    {
        return self::labels($kind)[$lang === 'fa' ? 'fa' : 'en'];
    }

    /**
     * What the intermediate level is called for this kind.
     *
     * A plant divides into areas; a pipeline would divide into sections. The
     * *table* is one table either way - only the word on the screen changes,
     * which is a labelling concern and belongs here rather than in a view.
     *
     * @return array{en:string,fa:string}
     */
    public static function areaLabels(string $kind): array
    {
        return match ($kind) {
            self::PIPELINE => ['en' => 'Section', 'fa' => 'سکشن'],
            self::MARINE => ['en' => 'Berth', 'fa' => 'اسکله'],
            default => ['en' => 'Area', 'fa' => 'ناحیه'],
        };
    }

    public static function areaLabel(string $kind, string $lang = 'fa'): string
    {
        return self::areaLabels($kind)[$lang === 'fa' ? 'fa' : 'en'];
    }
}
