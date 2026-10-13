<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\MaterialLibrary;
use App\Support\Reference\PipeReference;
use App\Support\Reference\FittingDimensions;

/**
 * The PMS body — ADR-008 §11.12 step 5.
 *
 * This is the layer that finally consumes three things that were built for PMS
 * and that PMS never reached: the **material library**, the **component
 * template qualifiers**, and the per-size **END_PREPARATION** policy. HANDOFF's
 * own lesson was "before building anything in a new module, check what already
 * exists for it", so nothing new is invented here that one of those three
 * already answers.
 *
 * Same governing instruction as the header: the PMS is transcribed from an
 * outside document, so this reports rather than refuses. A body row naming an
 * unknown spec is recorded and flagged; the transcriber finishes the sheet.
 */
final class PipingClassBody
{
    /** The component type is not one the platform models. */
    public const UNKNOWN_COMPONENT_TYPE = 'UNKNOWN_COMPONENT_TYPE';

    /** The template has a qualifier axis and this row names none. */
    public const QUALIFIER_MISSING = 'QUALIFIER_MISSING';

    /** The qualifier is not one this template offers. */
    public const QUALIFIER_UNKNOWN = 'QUALIFIER_UNKNOWN';

    /** A qualifier was given for a template that has no qualifier axis. */
    public const QUALIFIER_NOT_APPLICABLE = 'QUALIFIER_NOT_APPLICABLE';

    /** The material named cannot be resolved against the library. */
    public const MATERIAL_UNRESOLVED = 'MATERIAL_UNRESOLVED';

    /** The library does not carry this spec under this group. */
    public const MATERIAL_UNKNOWN = 'MATERIAL_UNKNOWN';

    /** The spec exists but does not come in the product form claimed. */
    public const PRODUCT_FORM_MISMATCH = 'PRODUCT_FORM_MISMATCH';

    /** The row's end type contradicts the class's END_PREPARATION at this size. */
    public const END_TYPE_AGAINST_POLICY = 'END_TYPE_AGAINST_POLICY';

    /** This row cites a header note number that does not exist. */
    public const NOTE_NOT_FOUND = 'NOTE_NOT_FOUND';

    /** The size is not one the platform can draw. */
    public const SIZE_UNKNOWN = 'SIZE_UNKNOWN';


    /** A rating was named that the flange tables cannot answer at this size. */
    public const RATING_UNANSWERABLE = 'RATING_UNANSWERABLE';

    public const SEVERITY_DISCREPANCY = 'DISCREPANCY';

    public const SEVERITY_INCOMPLETE = 'INCOMPLETE';

    public function __construct(
        private readonly MaterialLibrary $materials,
        private readonly PipeReference $pipe,
        private readonly ManualValves $valves,
        private readonly FittingDimensions $fittings,
    ) {}

    /**
     * Read reference geometry for an explicit PMS row, without changing it.
     * AVAILABLE means tabulated geometry, NOT class approval, pressure adequacy,
     * modelled cut length or independent verification of the source standard.
     */
    public function fittingDimensionForItem(PipingClassItem $item, ?float $capWallThicknessMm = null, ?string $thicknessSource = null): array
    {
        $scope = $this->fittings->sizeScope((string) $item->nps);
        if ($scope !== 'WITHIN_STANDARD_RANGE') {
            // Custom sizes belong to project specifications, not B16.9 tables.
            return $this->fittings->unavailable($scope);
        }
        $standard = strtoupper(trim((string) $item->dimensional_standard));
        if ($standard === '') {
            return $this->fittings->unavailable('STANDARD_UNSPECIFIED');
        }
        if (! preg_match('/^(?:ASME\s+)?B16\.9(?:-(\d{4}))?$/', $standard, $match)) {
            return $this->fittings->unavailable('STANDARD_NOT_COVERED');
        }
        if (isset($match[1]) && (int) $match[1] !== $this->fittings->source()['standard']['editionYear']) {
            return $this->fittings->unavailable('EDITION_NOT_COVERED');
        }
        if ($item->end_type !== 'BW') {
            return $this->fittings->unavailable($item->end_type ? 'END_TYPE_NOT_COVERED' : 'END_TYPE_UNSPECIFIED');
        }
        $size = $this->pipe->resolveLabel((string) $item->nps);
        if ($size === null) {
            return $this->fittings->unavailable('REFERENCE_SIZE_NOT_LOADED');
        }
        if ($size->dn !== $item->dn) {
            return $this->fittings->unavailable('SIZE_CONFLICT');
        }

        if (strtoupper(trim((string) $item->component_type)) === 'CAP'
            && $capWallThicknessMm !== null && trim((string) $thicknessSource) === '') {
            return $this->fittings->unavailable('CAP_THICKNESS_SOURCE_REQUIRED');
        }
        // Deliberately no schedule lookup. The caller must identify the source
        // of the applicable specified cap wall thickness; do not mutate PMS.
        $result = $this->fittings->lookup((string) $item->component_type,
            $item->qualifier, (string) $item->nps, $capWallThicknessMm);
        if (isset($result['selection'])) {
            $result['selection']['thicknessSource'] = trim((string) $thicknessSource);
        }

        return $result;
    }

    /**
     * Which component types this class permits. Absence from the body means
     * NOT PERMITTED — a PMS states what it allows and silence is a refusal,
     * not a gap to be filled by a default.
     *
     * @return list<string>
     */
    public function permittedComponentTypes(PipingClass $class): array
    {
        return PipingClassItem::query()
            ->where('piping_class_id', $class->id)
            ->distinct()
            ->orderBy('component_type')
            ->pluck('component_type')
            ->all();
    }

    /**
     * What this class buys for one component at one size, or null.
     *
     * Null means the class does not permit it there. It never falls back to a
     * neighbouring size or a parent class: piping classes do not inherit
     * (standing constraint) and a size band never overlaps another (§10).
     */
    public function itemFor(
        PipingClass $class,
        string $componentType,
        ?string $qualifier,
        int $dn,
    ): ?PipingClassItem {
        return PipingClassItem::query()
            ->where('piping_class_id', $class->id)
            ->where('component_type', $componentType)
            ->where('dn', $dn)
            ->when(
                $qualifier === null,
                fn ($q) => $q->whereNull('qualifier'),
                fn ($q) => $q->where('qualifier', $qualifier),
            )
            ->first();
    }

    /**
     * Everything wrong with one body row, as findings rather than refusals.
     *
     * @param  array<int,int>  $noteNumbers  the class's existing note numbers
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    public function findingsForItem(PipingClassItem $item, array $noteNumbers = []): array
    {
        $out = [];


        // ------------------------------------------- the axis: type + qualifier
        $template = null;
        try {
            $template = $this->pipe->template($item->component_type);
        } catch (\Throwable) {
            $out[] = $this->f(
                self::UNKNOWN_COMPONENT_TYPE,
                self::SEVERITY_DISCREPANCY,
                'component_type',
                "The platform models no component type {$item->component_type}. "
                .'An MTO line cannot be generated from it.',
            );
        }

        if ($template !== null) {
            $offered = $template['qualifiers'] ?? [];

            if ($offered === [] && $item->qualifier !== null) {
                $out[] = $this->f(
                    self::QUALIFIER_NOT_APPLICABLE,
                    self::SEVERITY_DISCREPANCY,
                    'qualifier',
                    "{$item->component_type} has no qualifier axis, but this row "
                    ."names {$item->qualifier}.",
                );
            } elseif ($offered !== [] && $item->qualifier === null) {
                // Not a discrepancy: a half-transcribed sheet, not a conflict.
                $out[] = $this->f(
                    self::QUALIFIER_MISSING,
                    self::SEVERITY_INCOMPLETE,
                    'qualifier',
                    "{$item->component_type} comes in "
                    .implode('/', $offered).'; this row does not say which.',
                );
            } elseif ($offered !== [] && ! in_array($item->qualifier, $offered, true)) {
                $out[] = $this->f(
                    self::QUALIFIER_UNKNOWN,
                    self::SEVERITY_DISCREPANCY,
                    'qualifier',
                    "{$item->qualifier} is not a {$item->component_type} the "
                    .'platform models ('.implode('/', $offered).').',
                );
            }
        }

        // ------------------------------------------------------------- size
        try {
            $this->pipe->byDn($item->dn);
        } catch (\Throwable) {
            $out[] = $this->f(
                self::SIZE_UNKNOWN,
                self::SEVERITY_DISCREPANCY,
                'dn',
                "DN {$item->dn} is not a size in the pipe-sizes pack, so no line "
                .'can be drawn at it.',
            );
        }

        // --------------------------------------------------------- material
        $out = array_merge($out, $this->materialFindings($item));

        // ------------------------------------------------------ the valve
        // A valve is the one component that needs three answers instead of
        // one - body, trim and operator - so its extra layer is checked by
        // ManualValves (§11.9) and folded in here. Everything that reads the
        // body (isConsumable, the future screen, MTO) then sees valve
        // findings without knowing valves exist.
        $out = array_merge($out, $this->valves->findingsFor($item));

        // -------------------------------------------------------- the notes
        foreach ((array) ($item->note_numbers ?? []) as $n) {
            if (! in_array((int) $n, $noteNumbers, true)) {
                $out[] = $this->f(
                    self::NOTE_NOT_FOUND,
                    self::SEVERITY_DISCREPANCY,
                    'note_numbers',
                    "This row cites note ({$n}), which the class header does not have.",
                );
            }
        }

        return $out;
    }

    /**
     * Rows whose end type contradicts the class's own END_PREPARATION policy
     * at that size.
     *
     * §11.4: END_PREPARATION is the one genuine class-level rule — "socket weld
     * below 1.5 inch, butt weld at 2 inch and above" must hold for every
     * component at that size or the joint cannot physically be made. This is a
     * policy and its observance, not two competing sources of truth, so the
     * item is checked against the rule rather than the two being merged.
     *
     * @return list<array{code:string,severity:string,field:string,message:string,item:string}>
     */
    public function endPreparationConflicts(PipingClass $class): array
    {
        $policy = [];
        foreach ($class->rules as $rule) {
            if ($rule->rule_kind === PipingRuleKind::END_PREPARATION) {
                $policy[(int) $rule->dn] = $rule->value['end_type'] ?? null;
            }
        }

        if ($policy === []) {
            return [];
        }

        $out = [];
        $items = PipingClassItem::query()
            ->where('piping_class_id', $class->id)
            ->whereNotNull('end_type')
            ->get();

        foreach ($items as $item) {
            $want = $policy[$item->dn] ?? null;
            if ($want === null || $want === $item->end_type) {
                continue;
            }
            $out[] = $this->f(
                self::END_TYPE_AGAINST_POLICY,
                self::SEVERITY_DISCREPANCY,
                'end_type',
                "At DN {$item->dn} the class calls for {$want} ends, but this "
                ."{$item->component_type} row says {$item->end_type}. One of the "
                .'two was transcribed wrongly, and a joint cannot be made from both.',
            ) + ['item' => (string) $item->id];
        }

        return $out;
    }

    /** Is the body complete enough for MTO to generate lines from? */
    public function isConsumable(PipingClass $class): bool
    {
        $notes = $class->notes->pluck('number')->map(fn ($n) => (int) $n)->all();

        $rows = PipingClassItem::query()
            ->where('piping_class_id', $class->id)
            ->get();

        foreach ($rows as $item) {
            foreach ($this->findingsForItem($item, $notes) as $f) {
                if ($f['severity'] === self::SEVERITY_DISCREPANCY) {
                    return false;
                }
            }
        }

        return $this->endPreparationConflicts($class) === []
            && $this->valves->operatorConsistency($class) === [];
    }

    /** @return list<array{code:string,severity:string,field:string,message:string}> */
    private function materialFindings(PipingClassItem $item): array
    {
        $group = $item->material_group_code;
        $spec = $item->material_spec_code;

        if ($group === null || $spec === null) {
            // A row may legitimately be prose-only while a sheet is being
            // transcribed. It is incomplete, not wrong.
            return [$this->f(
                self::MATERIAL_UNRESOLVED,
                self::SEVERITY_INCOMPLETE,
                'material_spec_code',
                'This row names no material from the library, so MTO cannot '
                .'order from it.',
            )];
        }

        if (! $this->materials->isKnown($group, $spec, $item->material_grade_key)) {
            return [$this->f(
                self::MATERIAL_UNKNOWN,
                self::SEVERITY_DISCREPANCY,
                'material_spec_code',
                "The library does not carry {$spec} "
                .($item->material_grade_key !== null ? "grade {$item->material_grade_key} " : '')
                ."under group {$group}.",
            )];
        }

        if ($item->product_form !== null
            && ! $this->materials->isKnown($group, $spec, $item->material_grade_key, $item->product_form)) {
            // productFormsFor returns the form OBJECTS, not their codes.
            $forms = array_column(
                $this->materials->productFormsFor($group, $spec),
                'code',
            );

            return [$this->f(
                self::PRODUCT_FORM_MISMATCH,
                self::SEVERITY_DISCREPANCY,
                'product_form',
                "{$spec} does not come as {$item->product_form}"
                .($forms === [] ? '.' : ' (it comes as '.implode('/', $forms).').'),
            )];
        }

        return [];
    }

    /** @return array{code:string,severity:string,field:string,message:string} */
    private function f(string $code, string $severity, string $field, string $message): array
    {
        return compact('code', 'severity', 'field', 'message');
    }
}
