<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassBranchRule;
use App\Models\PipingClassRule;
use App\Support\Piping\DnRange;
use App\Support\Piping\Exceptions\BranchLargerThanHeaderException;
use App\Support\Piping\Exceptions\NoRuleForSizeException;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\PipeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The PMS as data — writing rules, and resolving a size to an answer (ADR-008).
 *
 * ONE ROW PER SIZE, on the user's instruction. A PMS is read and transcribed
 * size by size - the engineer picks a diameter, then picks its schedule - so
 * the storage matches the document rather than compressing it into bands.
 *
 * That correction removed a whole class of problem rather than solving it. The
 * "does four inches belong to the band below or the band above" ambiguity
 * cannot arise when there is no boundary; the exclusion constraint became a
 * plain UNIQUE index; narrowing a class became a delete instead of a boundary
 * rewrite; and a schedule is now checked against ITS OWN size, so "SCH 160 is
 * not made at 3 1/2 inch" names the real problem instead of condemning the
 * band that size happened to sit in.
 *
 * The BRANCH table keeps its ranges. Different shape, different reasons:
 * overlap there is deliberate (a general rule plus narrower exceptions), and a
 * cell-by-cell matrix would be 120 rows for a class whose PMS states three.
 *
 * Resolution THROWS on a miss rather than returning null. A missing rule means
 * the PMS does not cover this size for this class, which is an engineering
 * error worth surfacing; a null becomes a default schedule becomes a wrong wall
 * on a pressure line.
 */
final class PipingClassRuleService
{
    public function __construct(private readonly PipeReference $reference)
    {
    }

    // ---------------------------------------------------------------- write

    /**
     * Record the rule for ONE size.
     *
     * The user's correction: no bands. A PMS is transcribed size by size, so
     * the storage matches that - pick a diameter, then state its schedule.
     *
     * @param  array<string,mixed>  $value
     */
    public function setRule(
        PipingClass $class,
        string $ruleKind,
        string $nps,
        array $value,
        ?string $sourceRef = null,
        ?string $noteEn = null,
        ?string $noteFa = null,
    ): PipingClassRule {
        if (! PipingRuleKind::isKnown($ruleKind)) {
            throw ValidationException::withMessages([
                'rule_kind' => "Unknown rule kind '{$ruleKind}'.",
            ]);
        }

        // A payload that does not match its kind stores fine and resolves to
        // nothing - the rule would look present on a screen and be invisible to
        // the resolver. Refuse it at the door.
        $key = PipingRuleKind::valueKey($ruleKind);
        if ($key === null || ! array_key_exists($key, $value)) {
            throw ValidationException::withMessages([
                'value' => "A {$ruleKind} rule must carry '{$key}'.",
            ]);
        }

        // The size must be one ASME actually makes. UnknownSizeException is
        // allowed to escape: a class cannot carry a size that does not exist.
        $size = $this->reference->size($nps);

        // Now checkable against THIS size rather than across a band, so the
        // refusal names the real problem: SCH 160 is not made at 3 1/2".
        if ($ruleKind === PipingRuleKind::PIPE_SCHEDULE) {
            $schedule = (string) $value[$key];

            if (! $this->reference->hasSchedule($size->nps, $schedule)) {
                throw ValidationException::withMessages([
                    'value' => "Schedule {$schedule} is not made at {$size->nps}\" in the ASME reference pack.",
                ]);
            }
        }

        // One row per size, so re-stating a size is a correction rather than a
        // conflict. The UNIQUE index still stops two rows existing.
        $existing = PipingClassRule::query()
            ->where('piping_class_id', $class->id)
            ->where('rule_kind', $ruleKind)
            ->where('dn', $size->dn)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'value' => $value,
                'source_ref' => $sourceRef ?? $existing->source_ref,
                'note_en' => $noteEn ?? $existing->note_en,
                'note_fa' => $noteFa ?? $existing->note_fa,
            ]);

            return $existing->fresh();
        }

        return $this->create(PipingClassRule::class, $class, [
            'rule_kind' => $ruleKind,
            'dn' => $size->dn,
            'nps' => $size->nps,
            'value' => $value,
            'source_ref' => $sourceRef,
            'note_en' => $noteEn,
            'note_fa' => $noteFa,
        ]);
    }

    /**
     * Record the same value for several sizes at once.
     *
     * Convenience for transcription, NOT a band: it writes one row per size and
     * the rows are independent afterwards. A PMS often does say "2 to 6 inch
     * SCH 40", and making the engineer type that six times would invite six
     * chances to mistype it - but what gets stored is still six rows, each
     * correctable on its own.
     *
     * @param  list<string>  $npsList
     * @param  array<string,mixed>  $value
     * @return list<PipingClassRule>
     */
    public function setRules(
        PipingClass $class,
        string $ruleKind,
        array $npsList,
        array $value,
        ?string $sourceRef = null,
    ): array {
        $written = [];

        foreach ($npsList as $nps) {
            $written[] = $this->setRule($class, $ruleKind, $nps, $value, $sourceRef);
        }

        return $written;
    }

    /**
     * The sizes this class carries for a rule kind, in ascending order.
     *
     * A class covers exactly what it lists - nothing is implied by falling
     * between two other entries.
     *
     * @return list<string> NPS labels
     */
    public function sizesCovered(PipingClass $class, string $ruleKind): array
    {
        return PipingClassRule::query()
            ->where('piping_class_id', $class->id)
            ->where('rule_kind', $ruleKind)
            ->orderBy('dn')
            ->pluck('nps')
            ->all();
    }

    /**
     * Remove one size from a class.
     *
     * With bands, narrowing a class meant rewriting boundaries and risking a
     * gap or an overlap. Now it is a delete.
     */
    public function removeRule(PipingClass $class, string $ruleKind, string $nps): bool
    {
        $dn = $this->reference->size($nps)->dn;

        return PipingClassRule::query()
            ->where('piping_class_id', $class->id)
            ->where('rule_kind', $ruleKind)
            ->where('dn', $dn)
            ->delete() > 0;
    }

    /**
     * Add a branch-table rule.
     *
     * @param  array<string,mixed>|null  $value
     */
    public function addBranchRule(
        PipingClass $class,
        DnRange $header,
        DnRange $branch,
        string $componentType,
        ?array $value = null,
        int $priority = 0,
        ?string $sourceRef = null,
    ): PipingClassBranchRule {
        $templates = $this->reference->templates();
        $known = array_column($templates, 'code');

        // STUB_IN is a real branch construction that has no component of its
        // own - the branch pipe is welded straight into the header. It is a
        // legitimate answer from a branch table even though nothing is bought.
        if ($componentType !== 'STUB_IN' && ! in_array($componentType, $known, true)) {
            throw ValidationException::withMessages([
                'component_type' => "Unknown component type '{$componentType}'.",
            ]);
        }

        $this->assertBoundsAreRealSizes($header);
        $this->assertBoundsAreRealSizes($branch);

        return $this->create(PipingClassBranchRule::class, $class, [
            'header_range' => $header->toSql(),
            'branch_range' => $branch->toSql(),
            'component_type' => $componentType,
            'value' => $value,
            'priority' => $priority,
            'source_ref' => $sourceRef,
        ]);
    }

    // --------------------------------------------------------------- resolve

    /**
     * The value this class specifies at this size.
     *
     * An equality lookup now, not a range containment test. A class covers
     * exactly the sizes it lists.
     *
     * @throws NoRuleForSizeException
     */
    public function resolve(PipingClass $class, string $ruleKind, int $dn): string|int|float
    {
        $rule = PipingClassRule::query()
            ->where('piping_class_id', $class->id)
            ->where('rule_kind', $ruleKind)
            ->where('dn', $dn)
            ->first();

        $value = $rule?->resolvedValue();

        if ($value === null) {
            throw new NoRuleForSizeException((string) $class->code, $ruleKind, $dn);
        }

        return $value;
    }

    /** Convenience for the commonest question: what wall does this class want? */
    public function scheduleFor(PipingClass $class, string $nps): string
    {
        $dn = $this->reference->size($nps)->dn;

        return (string) $this->resolve($class, PipingRuleKind::PIPE_SCHEDULE, $dn);
    }

    /**
     * Which fitting the PMS wants where a branch meets a header.
     *
     * Several rules legitimately match one cell - a general TEE plus narrower
     * exceptions - so the NARROWEST footprint wins. Ordering is by footprint,
     * then by explicit priority, so two equally narrow rules do not resolve by
     * insertion order.
     *
     * @throws BranchLargerThanHeaderException
     * @throws NoRuleForSizeException
     */
    public function resolveBranch(PipingClass $class, int $headerDn, int $branchDn): PipingClassBranchRule
    {
        // Not a CHECK constraint: this is a property of the question, not of
        // any stored rule. And it is a different fault from a PMS gap.
        if ($branchDn > $headerDn) {
            throw new BranchLargerThanHeaderException($headerDn, $branchDn);
        }

        $rule = PipingClassBranchRule::query()
            ->where('piping_class_id', $class->id)
            ->whereRaw('header_range @> ?::integer', [$headerDn])
            ->whereRaw('branch_range @> ?::integer', [$branchDn])
            ->orderByRaw(
                '(upper(header_range) - lower(header_range)) '
                .'+ (upper(branch_range) - lower(branch_range)) ASC'
            )
            ->orderBy('priority', 'desc')
            ->first();

        if ($rule === null) {
            throw new NoRuleForSizeException(
                (string) $class->code,
                "BRANCH(header DN{$headerDn})",
                $branchDn,
            );
        }

        return $rule;
    }

    // -------------------------------------------------------------- coverage

    /**
     * Which sizes this class answers for, and which it does not.
     *
     * With bands, "is it continuous?" needed range_agg because a hole between
     * two bands was invisible. Per size there is no such thing as a hole: the
     * class simply lists what it lists. The useful question changed with the
     * model, and it is now "does this class cover the sizes the project
     * actually uses?" - which only the caller knows, so it is a parameter.
     *
     * @param  list<string>|null  $requiredNps  sizes the project needs, or null to just report
     * @return array{sizes:list<string>,count:int,missing:list<string>,complete:bool}
     */
    public function coverage(PipingClass $class, string $ruleKind, ?array $requiredNps = null): array
    {
        $covered = $this->sizesCovered($class, $ruleKind);

        $missing = [];
        if ($requiredNps !== null) {
            foreach ($requiredNps as $nps) {
                if (! in_array($nps, $covered, true)) {
                    $missing[] = $nps;
                }
            }
        }

        return [
            'sizes' => $covered,
            'count' => count($covered),
            'missing' => $missing,
            'complete' => $missing === [],
        ];
    }

    /**
     * Sizes used by lines on this class that the class has no rule for.
     *
     * This is the question worth asking now: not "is the table contiguous"
     * but "is anything actually being built that the PMS does not cover".
     *
     * @return list<string> NPS labels
     */
    public function uncoveredSizesInUse(PipingClass $class, string $ruleKind): array
    {
        $covered = $this->sizesCovered($class, $ruleKind);

        $inUse = DB::table('components')
            ->join('piping_lines', 'components.piping_line_id', '=', 'piping_lines.id')
            ->where('piping_lines.piping_class_id', $class->id)
            ->whereNotNull('components.nominal_size')
            ->distinct()
            ->pluck('components.nominal_size')
            ->all();

        $missing = [];
        foreach ($inUse as $nps) {
            if (! in_array((string) $nps, $covered, true)) {
                $missing[] = (string) $nps;
            }
        }

        return $missing;
    }

    // --------------------------------------------------------------- helpers

    /**
     * Branch-table bounds must be real ASME sizes.
     *
     * Still needed here: the branch table keeps its ranges, because overlap
     * there is deliberate and a cell-by-cell matrix would be 120 rows for a
     * class whose PMS states three.
     */
    private function assertBoundsAreRealSizes(DnRange $range): void
    {
        $known = [];
        foreach ($this->reference->sizes() as $size) {
            $known[$size->dn] = true;
        }

        if (! isset($known[$range->from])) {
            throw ValidationException::withMessages([
                'dn_range' => "DN{$range->from} is not a size in the ASME reference pack.",
            ]);
        }

        $largest = max(array_keys($known));

        if (! isset($known[$range->toExclusive]) && $range->toExclusive <= $largest) {
            throw ValidationException::withMessages([
                'dn_range' => "DN{$range->toExclusive} is not a size in the ASME reference pack, "
                    .'so it cannot be the start of the next band.',
            ]);
        }
    }

    /**
     * @param  class-string<PipingClassRule|PipingClassBranchRule>  $model
     * @param  array<string,mixed>  $attributes
     */
    private function create(string $model, PipingClass $class, array $attributes): PipingClassRule|PipingClassBranchRule
    {
        return $model::create([
            'project_id' => $class->project_id,
            'company_id' => $class->company_id,
            'piping_class_id' => $class->id,
            ...$attributes,
        ]);
    }
}
