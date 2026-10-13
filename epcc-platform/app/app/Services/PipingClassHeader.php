<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Support\Reference\FlangeBolting;
use App\Support\Reference\FlangePtRatings;
use App\Support\Reference\FluidService;
use App\Support\Reference\MaterialLibrary;

/**
 * Transcribing a piping class header from a PMS document (ADR-008 §11.12 step 4).
 *
 * The governing instruction is the user's, 2026-09-24: **the PMS is transcribed
 * from outside, not authored here**, and **write-time refusals become "record
 * it, then raise a discrepancy."** Everything below follows from that.
 *
 * So this service does NOT validate a header into submission. It records what
 * the document says and then reports, separately, every way the record fails to
 * line up with what we know. A transcriber can finish the document in one pass;
 * an engineer clears the findings afterwards. Refusing the write would mean the
 * project's own PMS could not be entered into the system that exists to hold it.
 *
 * The one thing that IS refused is a header that contradicts itself
 * arithmetically — a maximum below a minimum, a negative corrosion allowance.
 * Those are database CHECK constraints, not judgements, and no document
 * contains them.
 */
final class PipingClassHeader
{
    /** The material named cannot be found in the library at all. */
    public const MATERIAL_UNKNOWN = 'MATERIAL_UNKNOWN';

    /** The spec exists but not under the group the header claims. */
    public const MATERIAL_GROUP_MISMATCH = 'MATERIAL_GROUP_MISMATCH';

    /** A material was typed but never resolved to a library reference. */
    public const MATERIAL_UNRESOLVED = 'MATERIAL_UNRESOLVED';

    /** The class states no material at all. */
    public const MATERIAL_ABSENT = 'MATERIAL_ABSENT';

    /** A rating this class carries has no bolting answer at the stated series. */
    public const SERIES_NOT_STATED = 'SERIES_NOT_STATED';

    /** The class has no corrosion allowance, so no wall calculation can run. */
    public const CORROSION_ALLOWANCE_ABSENT = 'CORROSION_ALLOWANCE_ABSENT';

    /** The allowance was typed as prose and could not be read as a number. */
    public const CORROSION_ALLOWANCE_UNREADABLE = 'CORROSION_ALLOWANCE_UNREADABLE';

    /** The class calls itself Category D and its own numbers say otherwise. */
    public const CATEGORY_D_BOUNDS_EXCEEDED = 'CATEGORY_D_BOUNDS_EXCEEDED';

    /** B31.3's category is missing, so the NDT percentages cannot be explained. */
    public const FLUID_SERVICE_ABSENT = 'FLUID_SERVICE_ABSENT';

    public const SEVERITY_DISCREPANCY = 'DISCREPANCY';

    public const SEVERITY_INCOMPLETE = 'INCOMPLETE';

    public function __construct(
        private readonly MaterialLibrary $materials,
        private readonly FlangeBolting $bolting,
        private readonly FluidService $fluidService,
        private readonly FlangePtRatings $ratings,
    ) {}

    /**
     * The class design pressure in kPa gauge, or null.
     *
     * The header holds a pressure CLASS, not a pressure, so the figure comes
     * from the rating tables at the class design temperature. Null whenever
     * any part of that chain is missing - a Category D check on a guessed
     * pressure would be worse than no check.
     */
    private function designGaugePressureKpa(PipingClass $class): ?float
    {
        if ($class->pressure_rating === null || $class->material_group_code === null) {
            return null;
        }
        $group = $this->materials->b165MaterialGroup(
            $class->material_group_code,
            (string) $class->material_spec_code,
            $class->material_grade_key,
        );
        if ($group === null || $class->design_temp_max_c === null) {
            return null;
        }
        // The flange tables, not the valve ones: a piping class rating is a
        // FLANGE class, and B16.5 is what the header's pressure_rating means.
        $kpa = $this->ratings->ratingAt(
            'B16.5', $group, $class->pressure_rating, (float) $class->design_temp_max_c,
        );

        return $kpa === null ? null : (float) $kpa;
    }

    /**
     * Resolve a typed material against the library WITHOUT deciding anything.
     *
     * Returns the reference when it resolves and nulls when it does not. The
     * caller writes either way; `findings()` reports the gap.
     *
     * @return array{group:string|null,spec:string|null,grade:string|null}
     */
    public function resolveMaterial(
        ?string $groupCode,
        ?string $specCode,
        ?string $gradeKey,
    ): array {
        $miss = ['group' => null, 'spec' => null, 'grade' => null];

        if ($groupCode === null || $specCode === null) {
            return $miss;
        }
        if (! $this->materials->isKnown($groupCode, $specCode, $gradeKey)) {
            return $miss;
        }

        return ['group' => $groupCode, 'spec' => $specCode, 'grade' => $gradeKey];
    }

    /**
     * Find the group a spec belongs to, for a transcriber who typed a spec
     * without one. Returns null when the spec is unknown or ambiguous —
     * ambiguity is not resolved by picking the first match.
     */
    public function groupForSpec(string $specCode): ?string
    {
        $hits = [];
        foreach ($this->materials->groups() as $group) {
            foreach ($this->materials->specsIn($group['code']) as $spec) {
                if (strcasecmp($spec['code'], $specCode) === 0) {
                    $hits[] = $group['code'];
                }
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Everything wrong with this header, as findings rather than refusals.
     *
     * DISCREPANCY means the document and our references disagree and somebody
     * must decide which is right. INCOMPLETE means the header simply has not
     * been filled in yet — a transcription in progress, not a conflict.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    public function findings(PipingClass $class): array
    {
        $out = [];

        // ---------------------------------------------------------- material
        $written = $class->material_as_written;
        $group = $class->material_group_code;
        $spec = $class->material_spec_code;

        if ($written === null && $group === null) {
            $out[] = $this->finding(
                self::MATERIAL_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'material',
                'The class states no material. PMS and MTO both read this field.',
            );
        } elseif ($group === null || $spec === null) {
            $out[] = $this->finding(
                self::MATERIAL_UNRESOLVED,
                self::SEVERITY_DISCREPANCY,
                'material',
                "The header says \"{$written}\", which has not been matched to the "
                .'material library. MTO cannot order from prose.',
            );
        } elseif (! $this->materials->isKnown($group, $spec, $class->material_grade_key)) {
            // The reference was stored but no longer resolves: either the
            // library changed under it or it was written past a validation.
            $code = $this->specExistsSomewhere($spec)
                ? self::MATERIAL_GROUP_MISMATCH
                : self::MATERIAL_UNKNOWN;
            $out[] = $this->finding(
                $code,
                self::SEVERITY_DISCREPANCY,
                'material',
                $code === self::MATERIAL_GROUP_MISMATCH
                    ? "Spec {$spec} exists in the library but not under group {$group}."
                    : "The library does not carry spec {$spec}.",
            );
        }

        // ------------------------------------------------ corrosion allowance
        if ($class->corrosion_allowance_mm === null) {
            $written = $class->corrosion_allowance_as_written;
            $out[] = $written === null
                ? $this->finding(
                    self::CORROSION_ALLOWANCE_ABSENT,
                    self::SEVERITY_INCOMPLETE,
                    'corrosion_allowance_mm',
                    'No corrosion allowance, so no B31.3 wall thickness can be calculated.',
                )
                : $this->finding(
                    self::CORROSION_ALLOWANCE_UNREADABLE,
                    self::SEVERITY_DISCREPANCY,
                    'corrosion_allowance_mm',
                    "The document says \"{$written}\", which is not a number of "
                    .'millimetres. A calculation cannot consume it.',
                );
        }

        // --------------------------------------------------- fluid service
        if ($class->fluid_service_category === null) {
            $out[] = $this->finding(
                self::FLUID_SERVICE_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'fluid_service_category',
                'Without B31.3 fluid service, the NDT percentages in this class '
                .'are numbers with no reason behind them.',
            );
        }

        // CATEGORY D IS THE ONLY SERVICE WITH NUMBERS IN IT. B31.3 300.2(a)
        // bounds it at 1 035 kPa gauge, 186 C and -29 C; every other service
        // is a judgement the owner or the engineering design makes, and this
        // engine has no standing to second-guess one. So this is the single
        // place a declared fluid service can contradict the sheet it came on.
        if ($class->fluid_service_category !== null) {
            $violations = $this->fluidService->categoryDViolations(
                $class->fluid_service_category,
                $class->design_temp_max_c === null ? null : (float) $class->design_temp_max_c,
                $this->designGaugePressureKpa($class),
                $class->design_temp_min_c === null ? null : (float) $class->design_temp_min_c,
            ) ?? [];

            foreach ($violations as $v) {
                $out[] = $this->finding(
                    self::CATEGORY_D_BOUNDS_EXCEEDED,
                    self::SEVERITY_DISCREPANCY,
                    'fluid_service_category',
                    'The class is declared Category D, but B31.3 300.2(a) bounds that '
                    ."service at {$v['limit']} {$v['unit']} and this class states "
                    ."{$v['actual']} {$v['unit']}. Either the service is wrong or the "
                    .'design condition is - and the examination extent turns on which.',
                );
            }
        }

        // -------------------------------------------------- flange series
        // Only a question above NPS 24, where the two B16.47 series are
        // different flanges at the same size. Below that B16.5 answers alone
        // and a series would be noise.
        if ($class->default_flange_series === null
            && $class->pressure_rating !== null
            && $this->bolting->seriesAnswering($class->pressure_rating, '30') !== []) {
            $out[] = $this->finding(
                self::SERIES_NOT_STATED,
                self::SEVERITY_INCOMPLETE,
                'default_flange_series',
                "This class is {$class->pressure_rating} and B16.47 offers both "
                .'Series A and B above NPS 24. They are different flanges, so a '
                .'take-off above NPS 24 cannot be priced until the class says which.',
            );
        }

        return $out;
    }

    /** Is this header complete enough for MTO to consume? */
    public function isConsumable(PipingClass $class): bool
    {
        foreach ($this->findings($class) as $f) {
            if ($f['code'] === self::MATERIAL_ABSENT
                || $f['code'] === self::MATERIAL_UNRESOLVED
                || $f['code'] === self::MATERIAL_UNKNOWN
                || $f['code'] === self::MATERIAL_GROUP_MISMATCH) {
                return false;
            }
        }

        return true;
    }

    private function specExistsSomewhere(string $specCode): bool
    {
        foreach ($this->materials->groups() as $group) {
            foreach ($this->materials->specsIn($group['code']) as $spec) {
                if (strcasecmp($spec['code'], $specCode) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{code:string,severity:string,field:string,message:string} */
    private function finding(
        string $code,
        string $severity,
        string $field,
        string $message,
    ): array {
        return compact('code', 'severity', 'field', 'message');
    }
}
