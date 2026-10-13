<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\PipingClassValveSpec;
use App\Support\Reference\MaterialLibrary;
use App\Support\Reference\PipeReference;
use App\Support\Reference\ValveActuators;
use App\Support\Reference\ValveConstruction;
use App\Support\Reference\ValveFaceToFace;
use App\Support\Reference\ValvePtRatings;

/**
 * Manual (non-automatic) valves in the PMS — ADR-008 §11.9.
 *
 * **User decision: non-automatic valves are to be added**, and with it the
 * discriminator that decides what this class is for: *if it has an actuator it
 * is a datasheet; if it does not, it is a PMS row.*
 *
 * A valve is the one component the PMS buys that needs three independent
 * answers instead of one. A flange is A105 and that is the whole material
 * question. A valve is a cast A216 WCB **body**, a 13Cr **trim**, and a
 * **gearbox** to turn it — three selections from three different sources, none
 * derivable from the others. This class checks all three, plus the standard
 * the valve is built to.
 *
 * IT CONSUMES THE TWO ASSETS THAT WERE BUILT AND NEVER WIRED
 * ----------------------------------------------------------
 * `VALVE_TRIM` (6 specs / 43 trims in the material library) and
 * `actuators.ref.json` both existed with no caller. Neither is re-implemented
 * here: `trimsFor()` answers which trims a valve type may carry, and
 * `manualOperatorsFor()` answers which operators can turn it. A third pack,
 * `valve-construction`, was added for the standard the valve is built to,
 * because nothing held that.
 *
 * SAME CONTRACT AS THE REST OF THE PMS (§11.10)
 * ---------------------------------------------
 * The PMS is transcribed from an outside document, so **nothing here throws
 * and nothing refuses a write**. Everything is a finding, in the two
 * severities the header and body already use:
 *
 *   INCOMPLETE  — the sheet has not been finished. No conflict yet.
 *   DISCREPANCY — the document and the references disagree. A human decides.
 *
 * WHAT IS DELIBERATELY NOT CHECKED
 * --------------------------------
 * There is no rule "a gearbox is required above NPS 8". That threshold is real
 * in practice and different in every project specification, and the repo has
 * already been bitten by a validator rule that encoded the author's habit
 * rather than a standard ("bolt count never falls as the flange grows" — true
 * of B16.5, false of B16.47). The monotonic check below needs no threshold: it
 * compares the class against itself.
 */
final class ManualValves
{
    /** The component type this whole class is about. */
    public const VALVE = 'VALVE';

    /** A valve row with no valve layer at all. */
    public const VALVE_SPEC_ABSENT = 'VALVE_SPEC_ABSENT';

    /** No construction standard named. */
    public const CONSTRUCTION_STANDARD_ABSENT = 'CONSTRUCTION_STANDARD_ABSENT';

    /** The construction standard is not one the pack holds. */
    public const CONSTRUCTION_STANDARD_UNKNOWN = 'CONSTRUCTION_STANDARD_UNKNOWN';

    /** API 600 on a ball valve: the standard does not cover this type. */
    public const CONSTRUCTION_STANDARD_NOT_FOR_TYPE = 'CONSTRUCTION_STANDARD_NOT_FOR_TYPE';

    /** API 602 at NPS 8: outside the standard's stated size scope. */
    public const CONSTRUCTION_STANDARD_OUT_OF_SCOPE = 'CONSTRUCTION_STANDARD_OUT_OF_SCOPE';

    /** A cast body under a forged-valve standard, or the reverse. */
    public const BODY_FORM_AGAINST_STANDARD = 'BODY_FORM_AGAINST_STANDARD';

    /** A valve body that is neither cast nor forged. */
    public const BODY_NOT_A_VALVE_BODY = 'BODY_NOT_A_VALVE_BODY';

    /** No trim named. */
    public const TRIM_ABSENT = 'TRIM_ABSENT';

    /** The trim is not in the library under that standard. */
    public const TRIM_UNKNOWN = 'TRIM_UNKNOWN';

    /** API 600 Trim 8 on a butterfly valve. */
    public const TRIM_NOT_FOR_VALVE_TYPE = 'TRIM_NOT_FOR_VALVE_TYPE';

    /** A trim quoted for a valve type that has no trim chart at all. */
    public const TRIM_NOT_CHARTED = 'TRIM_NOT_CHARTED';

    /** No operator named. */
    public const OPERATOR_ABSENT = 'OPERATOR_ABSENT';

    /** A check valve with an operator: it is opened by the flow. */
    public const OPERATOR_NOT_APPLICABLE = 'OPERATOR_NOT_APPLICABLE';

    /** A lever on a gate valve: the travel does not match. */
    public const OPERATOR_CANNOT_DRIVE = 'OPERATOR_CANNOT_DRIVE';

    /** A bigger valve given a lighter operator than a smaller one. */
    public const OPERATOR_LIGHTER_AT_LARGER_SIZE = 'OPERATOR_LIGHTER_AT_LARGER_SIZE';

    /** The row describes an actuated valve, which is a datasheet. */
    public const ACTUATED_VALVE_IS_A_DATASHEET = 'ACTUATED_VALVE_IS_A_DATASHEET';

    /** The row names no pressure class, so it has no envelope. */
    public const RATING_ABSENT = 'RATING_ABSENT';

    /** Class 400: B16.5 has it, B16.34 does not. A valve cannot be built to it. */
    public const RATING_NOT_A_VALVE_CLASS = 'RATING_NOT_A_VALVE_CLASS';

    /** The body material is not one B16.34 lists for a valve at all. */
    public const BODY_NOT_IN_B1634 = 'BODY_NOT_IN_B1634';

    /** A soft seat holds far less than the shell: the seat governs the valve. */
    public const SEAT_GOVERNS_THE_RATING = 'SEAT_GOVERNS_THE_RATING';

    /** A ball valve row that does not say floating or trunnion mounted. */
    public const BALL_DESIGN_NOT_STATED = 'BALL_DESIGN_NOT_STATED';

    /** The body is the wrong ASME B16.34 MATERIAL GROUP for this standard. */
    public const BODY_MATERIAL_GROUP_AGAINST_STANDARD = 'BODY_MATERIAL_GROUP_AGAINST_STANDARD';

    /** The spec is in B16.34 under several grades and the row names none. */
    public const BODY_GRADE_NOT_STATED = 'BODY_GRADE_NOT_STATED';

    /** The class design temperature is past the end of the material's curve. */
    public const DESIGN_TEMPERATURE_ABOVE_THE_CURVE = 'DESIGN_TEMPERATURE_ABOVE_THE_CURVE';

    /** B16.34 attaches a limit to this material ("not to be used over 371 C"). */
    public const MATERIAL_NOTE_LIMITS_TEMPERATURE = 'MATERIAL_NOTE_LIMITS_TEMPERATURE';

    /** The trim number has been withdrawn by its own standard. */
    public const TRIM_WITHDRAWN = 'TRIM_WITHDRAWN';

    /** B16.10 gives this valve no length at this class, size and end. */
    public const NO_TABULATED_LENGTH = 'NO_TABULATED_LENGTH';

    /** B16.10 offers more than one pattern and the row does not choose. */
    public const PATTERN_NOT_STATED = 'PATTERN_NOT_STATED';

    /** The row cites B16.10 for a valve B16.10 does not dimension. */
    public const DIMENSIONAL_STANDARD_DOES_NOT_COVER_IT = 'DIMENSIONAL_STANDARD_DOES_NOT_COVER_IT';

    public const SEVERITY_DISCREPANCY = PipingClassBody::SEVERITY_DISCREPANCY;

    public const SEVERITY_INCOMPLETE = PipingClassBody::SEVERITY_INCOMPLETE;

    /**
     * How much hand effort each manual operator represents, low to high. Not a
     * standard — it is the ordering every valve catalogue implies when it
     * fits a gearbox to the large end of a range and a bare handwheel to the
     * small end. CHAINWHEEL is deliberately absent: it is a way of REACHING a
     * valve, not of turning it harder, so it is not comparable.
     */
    private const OPERATOR_EFFORT = [
        'LEVER' => 1,
        'HANDWHEEL' => 1,
        'GEAR' => 2,
    ];

    public function __construct(
        private readonly MaterialLibrary $materials,
        private readonly ValveActuators $actuators,
        private readonly ValveConstruction $construction,
        private readonly ValvePtRatings $ratings,
        private readonly ValveFaceToFace $lengths,
        private readonly PipeReference $pipe,
    ) {}

    /**
     * Is this body row a valve, and therefore this class's business?
     *
     * CONTROL_VALVE and THREE_WAY_VALVE are not. A control valve is an
     * instrument with an actuator and is a datasheet by the user's own
     * discriminator; the three-way valve remains deferred.
     */
    public function isManualValveRow(PipingClassItem $item): bool
    {
        return $item->component_type === self::VALVE;
    }

    /**
     * Everything wrong with one valve row.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    public function findingsFor(PipingClassItem $item, ?PipingClassValveSpec $spec = null): array
    {
        if (! $this->isManualValveRow($item)) {
            return [];
        }

        $spec ??= PipingClassValveSpec::query()
            ->where('piping_class_item_id', $item->id)
            ->first();

        $type = $item->qualifier;

        if ($spec === null) {
            // Not a discrepancy. A half-transcribed sheet is incomplete, and
            // the body row on its own is still a legible purchase.
            return [$this->f(
                self::VALVE_SPEC_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'valve_spec',
                'This valve row carries no construction standard, trim or '
                .'operator. A valve cannot be bought from body material alone.',
            )];
        }

        return array_merge(
            $this->constructionFindings($item, $spec, $type),
            $this->bodyFindings($item, $spec),
            $this->ratingFindings($item),
            $this->lengthFindings($item, $spec, $type),
            $this->seatFindings($item, $spec, $type),
            $this->trimFindings($spec, $type),
            $this->operatorFindings($spec, $type),
            $this->actuatorFindings($item, $spec),
        );
    }

    /**
     * THE CHECK THAT NEEDS NO THRESHOLD.
     *
     * A project specification says where a gearbox becomes mandatory and every
     * project says something different, so no number is hardcoded. But a class
     * must at least be consistent with itself: if a 6 inch gate valve in this
     * class is geared, a 12 inch one with a bare handwheel is a transcription
     * error, because torque rises with size and bore pressure. The comparison
     * is between rows of the SAME class and the SAME valve type; nothing
     * outside the document is consulted, so this cannot be a rule about the
     * transcriber.
     *
     * @return list<array{code:string,severity:string,field:string,message:string,item:string}>
     */
    public function operatorConsistency(PipingClass $class): array
    {
        $rows = PipingClassItem::query()
            ->where('piping_class_id', $class->id)
            ->where('component_type', self::VALVE)
            ->orderBy('dn')
            ->get();

        $specs = PipingClassValveSpec::query()
            ->whereIn('piping_class_item_id', $rows->pluck('id')->all())
            ->get()
            ->keyBy('piping_class_item_id');

        /** @var array<string,array{dn:int,effort:int,operator:string}> $seen */
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            $spec = $specs[$row->id] ?? null;
            $op = $spec?->operator;
            $type = (string) $row->qualifier;

            if ($op === null || ! isset(self::OPERATOR_EFFORT[$op]) || $type === '') {
                continue;
            }

            $effort = self::OPERATOR_EFFORT[$op];
            $prev = $seen[$type] ?? null;

            if ($prev !== null && $effort < $prev['effort']) {
                $out[] = $this->f(
                    self::OPERATOR_LIGHTER_AT_LARGER_SIZE,
                    self::SEVERITY_DISCREPANCY,
                    'operator',
                    "At DN {$row->dn} this class gives the {$type} valve a "
                    ."{$op}, but at the smaller DN {$prev['dn']} it calls for a "
                    ."{$prev['operator']}. Torque rises with size, so the larger "
                    .'valve cannot need the lighter operator. One of the two '
                    .'lines was transcribed wrongly.',
                ) + ['item' => (string) $row->id];
            }

            if ($prev === null || $effort > $prev['effort']) {
                $seen[$type] = ['dn' => (int) $row->dn, 'effort' => $effort, 'operator' => $op];
            }
        }

        return $out;
    }

    /**
     * THE DROPDOWNS a transcription screen needs for one valve row, all three
     * filtered by the valve type so that no impossible combination is offered
     * in the first place. Empty lists are real answers.
     *
     * @return array{constructionStandards:list<array<string,mixed>>,trims:list<array<string,mixed>>,operators:list<array<string,mixed>>}
     */
    public function choicesFor(string $valveType, ?float $nps = null): array
    {
        return [
            'constructionStandards' => $nps === null
                ? $this->construction->forValveType($valveType)
                : $this->construction->forValveTypeAtSize($valveType, $nps),
            'trims' => $this->materials->trimsFor($valveType),
            'operators' => $this->actuators->manualOperatorsFor($valveType),
        ];
    }

    // ----------------------------------------------------------- internals --

    /** @return list<array{code:string,severity:string,field:string,message:string}> */
    private function constructionFindings(
        PipingClassItem $item,
        PipingClassValveSpec $spec,
        ?string $type,
    ): array {
        $code = $spec->construction_standard;

        if ($code === null) {
            return [$this->f(
                self::CONSTRUCTION_STANDARD_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'construction_standard',
                'No construction standard. A valve cannot be enquired for '
                .'without one - the face-to-face standard says nothing about '
                .'bonnet, stem or wall thickness.',
            )];
        }

        if (! $this->construction->isKnown($code)) {
            return [$this->f(
                self::CONSTRUCTION_STANDARD_UNKNOWN,
                self::SEVERITY_DISCREPANCY,
                'construction_standard',
                "{$code} is not a valve construction standard the platform "
                .'holds. It is recorded as written; a reviewer decides whether '
                .'it is a typo or a standard the pack is missing.',
            )];
        }

        $out = [];

        if ($type !== null && ! $this->construction->covers($code, $type)) {
            $covers = implode('/', $this->construction->get($code)['valveTypes']);
            $out[] = $this->f(
                self::CONSTRUCTION_STANDARD_NOT_FOR_TYPE,
                self::SEVERITY_DISCREPANCY,
                'construction_standard',
                "{$code} covers {$covers} valves, and this row is a {$type}.",
            );
        }

        $nps = $this->npsOf($item);
        if ($nps !== null && ! $this->construction->coversNps($code, $nps)) {
            $s = $this->construction->get($code);
            $window = $this->windowText($s['npsMin'], $s['npsMax']);
            // The scope figure is NOT verified against a purchased copy of the
            // standard, and the finding says so rather than quoting it as the
            // standard's requirement. Standing constraint, from the allowable
            // stresses: unverified data is reported, never cited.
            $caveat = $this->construction->scopeIsCitable($code)
                ? ''
                : ' (Scope read from the standard title, not from a purchased '
                  .'copy - check before citing it to a vendor.)';
            $out[] = $this->f(
                self::CONSTRUCTION_STANDARD_OUT_OF_SCOPE,
                self::SEVERITY_DISCREPANCY,
                'construction_standard',
                "{$code} applies {$window}, and this row is at NPS "
                .rtrim(rtrim(number_format($nps, 3, '.', ''), '0'), '.')
                .'.'.$caveat,
            );
        }

        return $out;
    }

    /**
     * The valve BODY. Standing constraint: castings are body material only —
     * a cast grade never describes a trim or a bolt — and the forged small-bore
     * valve is the other half of the pair. Anything else in the body material
     * column of a valve row is wrong.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    private function bodyFindings(PipingClassItem $item, PipingClassValveSpec $spec): array
    {
        $group = $item->material_group_code;

        if ($group === null) {
            // Already reported by the body as MATERIAL_UNRESOLVED; saying it
            // twice would double every count on the screen.
            return [];
        }

        if (! in_array($group, ['CASTINGS', 'FORGINGS'], true)) {
            return [$this->f(
                self::BODY_NOT_A_VALVE_BODY,
                self::SEVERITY_DISCREPANCY,
                'material_group_code',
                "A valve body is cast or forged; this row's body material is "
                ."in group {$group}. A valve body is never bought as pipe or "
                .'plate.',
            )];
        }

        $code = $spec->construction_standard;
        if ($code === null) {
            return [];
        }

        // THE SHARPER CHECK, AND FOR SOME STANDARDS THE ONLY ONE THAT BITES.
        // Three construction standards have now been read and not one of them
        // restricts the product form - API 600, 602 and 603 all say "cast or
        // forged". API 603 restricts something else entirely: Table 4 gives
        // the body as "As selected from ASME B16.34, Group 2 and Group 3",
        // which is what "corrosion-resistant" in its title actually means. A
        // Group 1 carbon or low-alloy body is outside API 603 however it was
        // made, and that is checkable because valve-pt-ratings holds all 50
        // B16.34 groups.
        $permittedGroups = $this->construction->bodyMaterialGroups($code);
        if ($permittedGroups !== null && $item->material_spec_code !== null) {
            $mg = $this->ratings->groupFor(
                $item->material_spec_code,
                $item->material_grade_key,
            );
            $family = $mg === null ? null : explode('.', $mg)[0];
            if ($family !== null && ! in_array($family, $permittedGroups, true)) {
                return [$this->f(
                    self::BODY_MATERIAL_GROUP_AGAINST_STANDARD,
                    self::SEVERITY_DISCREPANCY,
                    'material_spec_code',
                    "{$code} takes its body from ASME B16.34 material group "
                    .implode(' and ', $permittedGroups).", and "
                    .$item->material_spec_code." resolves to group {$mg}. That "
                    .'is the whole point of the standard: it is the '
                    .'corrosion-resistant cut of the gate valve, so a carbon or '
                    .'low-alloy body is outside it however it was made.',
                )];
            }
        }

        $wants = $this->construction->bodyGroups($code);
        if ($wants === null || in_array($group, $wants, true)) {
            return [];
        }

        return [$this->f(
            self::BODY_FORM_AGAINST_STANDARD,
            self::SEVERITY_DISCREPANCY,
            'material_group_code',
            "{$code} is a ".strtolower(implode('/', $wants))
            ." valve, but this row's body material is ".strtolower($group).'.'
            // TWICE NOW, a single-product-form claim has collapsed on contact
            // with the purchased standard - three times counting API 603,
            // which does not turn on product form at all. So a finding built
            // on an UNVERIFIED body-form claim says so in its own message,
            // exactly as the size-scope finding does.
            .($this->construction->scopeIsCitable($code)
                ? ' Verified against the purchased standard.'
                : ' *Body form read from the standard title, not from a purchased '
                  .'copy. Every such claim checked so far has turned out to permit '
                  .'both forms.*'),
        )];
    }

    /**
     * THE RATING, FROM THE VALVE STANDARD.
     *
     * A valve's pressure-temperature envelope comes from **ASME B16.34**, not
     * from B16.5. The platform held only flange ratings until this round, and
     * the difference is not academic:
     *
     *   - B16.34 does not tabulate **Class 400** at all, though B16.5 does. A
     *     PMS line saying "Class 400 gate valve" names something the valve
     *     standard cannot rate.
     *   - B16.34 adds **Class 4500**, which no flange standard here carries.
     *   - B16.34 adds **Special Class**, a higher envelope for valves that have
     *     had extra examination. Nothing in the flange tables can produce it.
     *
     * The class header supplies the design temperature, so this is the first
     * check in the system that puts a material, a class and a temperature
     * together and asks whether the valve can actually hold.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    private function ratingFindings(PipingClassItem $item): array
    {
        $rating = $item->pressure_rating;

        if ($rating === null || trim($rating) === '') {
            return [$this->f(
                self::RATING_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'pressure_rating',
                'No pressure class. Without one the valve has no envelope and no '
                .'flange to mate with.',
            )];
        }

        $out = [];

        if (! $this->ratings->tabulatesPressureClass($rating)) {
            $has = implode('/', $this->ratings->pressureClasses());
            $out[] = $this->f(
                self::RATING_NOT_A_VALVE_CLASS,
                self::SEVERITY_DISCREPANCY,
                'pressure_rating',
                "ASME B16.34 does not rate valves at {$rating}. It tabulates "
                ."{$has} - Class 400 is a FLANGE class in B16.5 with no valve "
                .'counterpart. The line was probably copied from the flange sheet.',
            );

            return $out;
        }

        $spec = $item->material_spec_code;
        if ($spec === null) {
            return $out;   // already reported by the body as MATERIAL_UNRESOLVED
        }

        $group = $this->ratings->groupFor($spec, $item->material_grade_key);

        if ($group === null && ! $this->ratings->listsSpec($spec)) {
            $out[] = $this->f(
                self::BODY_NOT_IN_B1634,
                self::SEVERITY_DISCREPANCY,
                'material_spec_code',
                "B16.34 does not list {$spec}"
                .($item->material_grade_key === null ? '' : " {$item->material_grade_key}")
                .' among the materials a valve body may be made from, so the '
                .'valve has no rating curve. Either the spec is wrong or the '
                .'valve is outside the standard.',
            );

            return $out;
        }

        if ($group === null) {
            // The spec IS in the standard - under more than one grade, in more
            // than one rating group. A216 WCB sits in group 1.1 and A216 WCC in
            // 1.2, and they are different curves. Guessing one would hand the
            // valve someone else's envelope, so the row is incomplete rather
            // than wrong.
            $grades = array_values(array_filter(array_map(
                static fn (array $g): ?string => $g['grade'],
                $this->ratings->gradesOfSpec($spec),
            )));
            $out[] = $this->f(
                self::BODY_GRADE_NOT_STATED,
                self::SEVERITY_INCOMPLETE,
                'material_grade_key',
                "B16.34 lists {$spec} under ".implode('/', $grades)
                .', which fall in different rating groups. Without the grade the '
                .'valve has no single rating curve.',
            );

            return $out;
        }

        // The class header carries the design temperature; without one there is
        // nothing to check the curve against, and that gap is the header's
        // finding to raise, not this one's.
        $temp = $item->pipingClass?->design_temp_max_c;
        if ($temp === null) {
            return $out;
        }

        if ($this->ratings->ratingAt($group, $rating, (float) $temp) === null) {
            $why = $this->ratings->refusalFor($group, $rating, (float) $temp);
            if ($why === 'ABOVE_THE_LAST_TABULATED_TEMPERATURE') {
                $out[] = $this->f(
                    self::DESIGN_TEMPERATURE_ABOVE_THE_CURVE,
                    self::SEVERITY_DISCREPANCY,
                    'pressure_rating',
                    "The class is designed to {$temp} C, which is past the end of "
                    ."the B16.34 curve for group {$group}. This material cannot "
                    .'be used at that temperature in any pressure class.',
                );
            }
        }

        foreach ($this->ratings->notesForMaterial($spec, $item->material_grade_key) as $note) {
            // "Not to be used over 371 C" sits under a table that still prints
            // numbers above 371 C. A consumer that reads only the grid
            // over-rates the valve, so the note is surfaced next to the rating.
            if (preg_match('/not to be used over (\d+)\s*°?C/i', $note['text'], $m)
                && (float) $temp > (float) $m[1]) {
                $out[] = $this->f(
                    self::MATERIAL_NOTE_LIMITS_TEMPERATURE,
                    self::SEVERITY_DISCREPANCY,
                    'material_spec_code',
                    "B16.34 note ({$note['ref']}) on group {$group} says: "
                    .rtrim($note['text'], '.').". The class is designed to {$temp} C.",
                );
            }
        }

        return $out;
    }

    /**
     * THE LENGTH, FROM THE DIMENSIONAL STANDARD.
     *
     * A rating is not a drawing. B16.34 says the valve holds 600 class at 200
     * degrees; B16.10 says it is 559 mm long. The second is what closes an
     * isometric and sets the cut length of the pipe either side of it, and the
     * platform had no source for it until this round.
     *
     * The most useful finding here is the quiet one: B16.10 frequently offers
     * TWO patterns - a Class 600 gate valve is 559 mm long or 457 mm short -
     * and a PMS row that names neither has not specified the valve. Nothing
     * downstream can tell, because both numbers are perfectly valid.
     *
     * @param  list<string>  $type
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    private function lengthFindings(PipingClassItem $item, ?PipingClassValveSpec $spec, string $type): array
    {
        $rating = $item->pressure_rating;
        $nps = $item->nps;
        if ($rating === null || $nps === null || trim($rating) === '') {
            return [];   // already reported as RATING_ABSENT / SIZE_UNKNOWN
        }

        $stated = $item->dimensional_standard;
        $citesB1610 = $stated !== null && str_contains(
            strtoupper(str_replace(' ', '', $stated)),
            'B16.10',
        );

        // B16.10 dimensions flanged, buttwelding and wafer valves. It says
        // nothing about a socket-weld or threaded valve, so neither does this.
        $endMap = ['FL' => 'RF', 'BW' => 'BW', 'GF' => 'GROOVED'];
        $end = $endMap[strtoupper((string) $item->end_type)] ?? null;
        if ($end === null) {
            return [];
        }

        // A PMS states the pattern in the description - "GATE VALVE, BOLTED
        // BONNET, LONG PATTERN" - because there is nowhere else for it to go.
        // The row is read for it before the row is accused of omitting it.
        $pattern = $this->patternInDescription($item->description_as_written);

        $refusal = $this->lengths->refusalFor($type, $rating, $nps, $end, $pattern);

        if ($refusal === 'VALVE_TYPE_NOT_IN_B1610') {
            // Only worth saying when the row claims B16.10 for itself. A
            // needle valve with no dimensional standard is not wrong here.
            return $citesB1610 ? [$this->f(
                self::DIMENSIONAL_STANDARD_DOES_NOT_COVER_IT,
                self::SEVERITY_DISCREPANCY,
                'dimensional_standard',
                "The row cites ASME B16.10, which tabulates no length for a "
                ."{$type} valve. B16.10 covers gate, globe, check, ball, plug, "
                .'butterfly, angle and knife gate only.',
            )] : [];
        }

        if ($refusal === 'CLASS_HAS_NO_TABULATED_LENGTH') {
            return [$this->f(
                self::NO_TABULATED_LENGTH,
                self::SEVERITY_DISCREPANCY,
                'pressure_rating',
                "ASME B16.10 gives no length for a Class {$rating} valve. The "
                .'valve can be rated and still not be dimensionable - Class '
                .'4500 is rated by B16.34 and dimensioned by nothing.',
            )];
        }

        if ($refusal === 'NOT_OFFERED_AT_THIS_SIZE_OR_END') {
            return [$this->f(
                self::NO_TABULATED_LENGTH,
                self::SEVERITY_DISCREPANCY,
                'nps',
                "ASME B16.10 tabulates no {$type} valve at NPS {$nps} in Class "
                ."{$rating}. Either the size is outside the standard or this "
                .'valve is a special.',
            )];
        }

        if ($refusal === 'SEVERAL_PATTERNS_OFFERED' || $refusal === 'SEVERAL_BODY_STYLES_OFFERED') {
            if (! $citesB1610) {
                // The row never claimed B16.10. Something else may govern its
                // length and we are not entitled to an opinion about it.
                return [];
            }
            $offered = [];
            foreach ($this->lengths->patternsFor($type, $rating, $nps) as $p) {
                if ($p['endType'] !== $end) {
                    continue;
                }
                $offered[] = ($p['pattern'] ?? 'unnamed').' '.$p['mm'].' mm';
            }

            return [$this->f(
                self::PATTERN_NOT_STATED,
                self::SEVERITY_INCOMPLETE,
                'description_as_written',
                'ASME B16.10 offers more than one length here and the row does '
                .'not say which: '.implode(', ', array_unique($offered))
                .'. A spool cannot be dimensioned until it does.',
            )];
        }

        return [];
    }

    /**
     * A VALVE IS NOT ITS SHELL.
     *
     * API 608 clause 4.1: *"The valve pressure-temperature rating shall be the
     * lesser of the shell rating or the seat and seal rating."* Until this
     * round the platform read the shell rating out of B16.34 and called it the
     * valve rating. For a soft-seated ball valve that is not a rounding error:
     * a Class 600 group 1.1 shell is 102 bar at 38 °C, and the same valve with
     * a PTFE seat above DN 100 is **19.7 bar**.
     *
     * The finding needs the ball design, and a PMS row does not carry one -
     * which is the same gap API 598 hit when it refused to say which tests a
     * bare "BALL" takes. API 608 clause 1.4 names the two designs, so the gap
     * is now reportable rather than silent.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    private function seatFindings(PipingClassItem $item, ?PipingClassValveSpec $spec, string $type): array
    {
        if ($type !== 'BALL' || $spec === null) {
            return [];
        }

        $seat = $this->seatMaterialOf($spec);
        if ($seat === null) {
            return [];   // metal seated, or a trim the seat table does not cover
        }

        $design = $this->ballDesignInDescription($item->description_as_written);
        if ($design === null) {
            $named = $this->construction->designVariants('API 608') ?? [];

            return [$this->f(
                self::BALL_DESIGN_NOT_STATED,
                self::SEVERITY_INCOMPLETE,
                'description_as_written',
                'A soft-seated ball valve is rated by its SEAT, not its shell, and '
                .'the seat rating differs between the two designs API 608 clause '
                .'1.4 recognises: '.implode(' and ', $named).'. The row names '
                .'neither, so the valve has no envelope - and API 598 cannot say '
                .'which tests it takes either.',
            )];
        }

        $class = $item->pressure_rating;
        $group = $item->material_spec_code === null ? null : $this->ratings->groupFor(
            $item->material_spec_code,
            $item->material_grade_key,
        );
        $temp = $item->pipingClass?->design_temp_max_c;
        if ($class === null || $group === null || $temp === null || $item->dn === null) {
            return [];
        }

        $governing = $this->ratings->governingRatingAt(
            $group, $class, (float) $temp, $seat, 'BALL',
            ['ballDesign' => $design], (int) $item->dn,
        );
        if ($governing['governedBy'] !== 'SEAT' || $governing['kPa'] === null) {
            return [];
        }

        $shell = $this->ratings->ratingAt($group, $class, (float) $temp);
        $seatBar = round($governing['kPa'] / 100, 1);
        $shellBar = $shell === null ? null : round($shell / 100, 1);

        return [$this->f(
            self::SEAT_GOVERNS_THE_RATING,
            self::SEVERITY_DISCREPANCY,
            'pressure_rating',
            "Class {$class} puts the shell at {$shellBar} bar at {$temp} C, but the "
            ."{$seat} seat on a ".strtolower(str_replace('_', ' ', $design))
            ." ball valve at DN {$item->dn} holds {$seatBar} bar. API 608 clause "
            .'4.1: the valve rating is the LESSER of the two. Specifying the class '
            .'and stopping is how a valve gets bought for five times the pressure '
            .'it can hold.',
        )];
    }

    /** The seat material of a ball trim, where the seat table covers it. */
    private function seatMaterialOf(PipingClassValveSpec $spec): ?string
    {
        $code = strtoupper((string) $spec->trim_code);

        return match (true) {
            $code === 'PTFE' => 'PTFE',
            $code === 'RPTFE' || $code === 'R-PTFE' => 'RPTFE',
            default => null,
        };
    }

    /** FLOATING or TRUNNION_MOUNTED, as named in the transcribed description. */
    private function ballDesignInDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $d = strtoupper($description);
        if (str_contains($d, 'TRUNNION')) {
            return 'TRUNNION_MOUNTED';
        }
        if (str_contains($d, 'FLOATING') || str_contains($d, 'SEAT-SUPPORTED')) {
            return 'FLOATING';
        }

        return null;
    }

    /** The pattern named in the transcribed description, if any. */
    private function patternInDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $d = strtoupper($description);
        foreach ([
            'ROUND_PORT_FULL_BORE' => ['FULL BORE', 'FULL PORT', 'ROUND PORT'],
            'REGULAR_AND_VENTURI' => ['REGULAR AND VENTURI'],
            'VENTURI' => ['VENTURI'],
            'REGULAR' => ['REGULAR PATTERN'],
            'SHORT' => ['SHORT PATTERN'],
            'LONG' => ['LONG PATTERN'],
        ] as $pattern => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($d, $needle)) {
                    return $pattern;
                }
            }
        }

        return null;
    }

    /** @return list<array{code:string,severity:string,field:string,message:string}> */
    private function trimFindings(PipingClassValveSpec $spec, ?string $type): array
    {
        $chart = $type === null ? null : $this->materials->trimStandardFor($type);

        // NOT EVERY STANDARD NUMBERS ITS TRIM. API 600, 623 and 594 each print
        // a numbered table; API 599 prints none - a plug valve's seat, sleeve
        // and plug facing are agreed on the purchase order under its clause 6.
        // Demanding a trim NUMBER from a plug valve row asks for a vocabulary
        // the standard has not got, so the absence is not a finding here.
        if ($chart === null && ! $spec->hasTrim()) {
            return [];
        }

        if (! $spec->hasTrim()) {
            return [$this->f(
                self::TRIM_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'trim_code',
                'No trim. The body material says nothing about the seat, disc '
                .'and stem, which are what actually meet the fluid.',
            )];
        }

        if ($type === null) {
            return [];
        }

        if ($chart === null) {
            // NEEDLE and DIAPHRAGM. Both packs agree they have no chart.
            return [$this->f(
                self::TRIM_NOT_CHARTED,
                self::SEVERITY_DISCREPANCY,
                'trim_standard',
                "The library holds no trim chart for a {$type} valve, yet this "
                .'row names a trim. Recorded as written; it needs a reviewer, '
                .'because a trim number from another valve type does not '
                .'transfer.',
            )];
        }

        // A WITHDRAWN TRIM IS NOT AN UNKNOWN ONE. API 600-2021 Table 8 prints
        // "TRIM NUMBER 1 IS OBSOLETE"; the industry charts this library grew
        // from still list it. A sheet transcribed from an older revision will
        // say Trim 1, and the useful answer is "the standard dropped this",
        // not "we have never heard of it".
        if ($this->materials->trimIsObsolete((string) $spec->trim_standard, (string) $spec->trim_code)) {
            return [$this->f(
                self::TRIM_WITHDRAWN,
                self::SEVERITY_DISCREPANCY,
                'trim_code',
                "{$spec->trimLabel()} has been withdrawn by its own standard - "
                .'API 600-2021 Table 8 prints "TRIM NUMBER 1 IS OBSOLETE". The '
                .'sheet was probably transcribed from an older revision.',
            )];
        }

        $permitted = $this->materials->trimsFor($type);
        $match = array_filter(
            $permitted,
            fn (array $t): bool => $t['code'] === $spec->trim_code
                && $t['standard'] === $spec->trim_standard,
        );

        if ($match !== []) {
            return [];
        }

        // Is it a real trim, but the wrong valve type's? That is the API 600
        // trap: its chart is quoted for every valve in the industry.
        $anywhere = array_filter(
            $this->materials->trims(),
            fn (array $t): bool => $t['code'] === $spec->trim_code
                && $t['standard'] === $spec->trim_standard,
        );

        if ($anywhere !== []) {
            $belongs = implode('/', reset($anywhere)['valveTypes']);

            return [$this->f(
                self::TRIM_NOT_FOR_VALVE_TYPE,
                self::SEVERITY_DISCREPANCY,
                'trim_code',
                "{$spec->trimLabel()} is a {$belongs} trim, and this row is a "
                ."{$type}. The {$chart} chart is the one that applies here.",
            )];
        }

        return [$this->f(
            self::TRIM_UNKNOWN,
            self::SEVERITY_DISCREPANCY,
            'trim_code',
            "The library has no trim {$spec->trim_code} under "
            ."{$spec->trim_standard}.",
        )];
    }

    /** @return list<array{code:string,severity:string,field:string,message:string}> */
    private function operatorFindings(PipingClassValveSpec $spec, ?string $type): array
    {
        $op = $spec->operator;

        if ($type === null) {
            return [];
        }

        $travel = $this->actuators->travelOf($type);

        // A check valve is opened by the flow. No travel, therefore no
        // operator, and this is the one component where absence is correct.
        if ($travel === null) {
            return $op === null ? [] : [$this->f(
                self::OPERATOR_NOT_APPLICABLE,
                self::SEVERITY_DISCREPANCY,
                'operator',
                "A {$type} valve has no operator - it is worked by the flow, "
                ."not by hand - yet this row names a {$op}.",
            )];
        }

        if ($op === null) {
            return [$this->f(
                self::OPERATOR_ABSENT,
                self::SEVERITY_INCOMPLETE,
                'operator',
                'No operator. A hand-operated valve is bought with the thing '
                .'that turns it, and the choice changes with size.',
            )];
        }

        // CHAINWHEEL sits on top of a handwheel or a gearbox, so it carries no
        // travel claim of its own and is exempt from the travel test.
        if ($op === 'CHAINWHEEL' || $this->actuators->canDrive($op, $type)) {
            return [];
        }

        $can = implode('/', array_column(
            $this->actuators->manualOperatorsFor($type), 'code'
        ));

        return [$this->f(
            self::OPERATOR_CANNOT_DRIVE,
            self::SEVERITY_DISCREPANCY,
            'operator',
            "A {$type} valve is {$travel}, and a {$op} cannot drive it. "
            ."The operators that can are {$can}.",
        )];
    }

    /**
     * THE BOUNDARY, READ OUT OF THE TEXT.
     *
     * The user's discriminator is that an actuated valve is a datasheet, not a
     * PMS row. The `operator` column cannot hold an actuator (the schema
     * refuses it), but the transcriber's free-text line can, and a document
     * that says "MOTOR OPERATED" has put a datasheet item on a PMS sheet. This
     * reports; it does not refuse, because the transcriber may not correct the
     * client's document.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    private function actuatorFindings(PipingClassItem $item, PipingClassValveSpec $spec): array
    {
        $text = strtoupper(trim(
            ($spec->description_as_written ?? '').' '.($item->description_as_written ?? '')
        ));

        if ($text === '') {
            return [];
        }

        $powered = [
            'MOTOR OPERATED', 'MOTOR-OPERATED', 'MOV', 'ACTUATOR', 'ACTUATED',
            'PNEUMATIC', 'HYDRAULIC', 'ELECTRIC', 'SOLENOID', 'GAS OVER OIL',
        ];

        foreach ($powered as $needle) {
            if (str_contains($text, $needle)) {
                return [$this->f(
                    self::ACTUATED_VALVE_IS_A_DATASHEET,
                    self::SEVERITY_DISCREPANCY,
                    'description_as_written',
                    "This row's description says \"{$needle}\". A valve with an "
                    .'actuator is a datasheet item with its own tag, not a PMS '
                    .'row: the PMS says what to buy for any line in the class, '
                    .'and an actuated valve is specified one at a time. '
                    .'Recorded as written for the reviewer.',
                )];
            }
        }

        return [];
    }

    /**
     * `byDn()` returns a PipeSize OBJECT, not an array - the same shape trap
     * `productFormsFor()` set during the body round, where an `implode` over
     * form objects became a runtime error. An unknown size is not this class's
     * finding to raise: the body already reports SIZE_UNKNOWN.
     */
    private function npsOf(PipingClassItem $item): ?float
    {
        try {
            return $this->pipe->byDn((int) $item->dn)->npsDecimal;
        } catch (\Throwable) {
            return null;
        }
    }

    private function windowText(?float $min, ?float $max): string
    {
        $n = static fn (float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');

        if ($min !== null && $max !== null) {
            return 'from NPS '.$n($min).' to NPS '.$n($max);
        }
        if ($min !== null) {
            return 'from NPS '.$n($min).' upwards';
        }
        if ($max !== null) {
            return 'up to NPS '.$n($max);
        }

        return 'at every size';
    }

    /** @return array{code:string,severity:string,field:string,message:string} */
    private function f(string $code, string $severity, string $field, string $message): array
    {
        return compact('code', 'severity', 'field', 'message');
    }
}
