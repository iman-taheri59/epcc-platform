<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\PipingLine;
use App\Support\Piping\Exceptions\NoRuleForSizeException;
use App\Support\Piping\PipingRuleKind;
use App\Support\Piping\Verdict;
use App\Support\Reference\Exceptions\UnknownSizeException;
use App\Support\Reference\PipeReference;

/**
 * Checking what was modelled against what the PMS specifies (ADR-008 §5, §6).
 *
 * This is the payoff for making the PMS data. Once the class is machine
 * readable, importing a 3D model stops being transcription and becomes an
 * AUDIT: thousands of components checked against their class in seconds, with
 * a report of every deviation.
 *
 * TWO LAYERS, KEPT SEPARATE — the distinction is the whole point:
 *
 *   physical   (ADR-007)  does this size/schedule EXIST in ASME?   → INVALID
 *   contractual(ADR-008)  is it what this CLASS specifies?         → WARNING
 *
 * A 6" SCH 60 pipe is perfectly real and perfectly wrong on a class that says
 * SCH 40. Calling that "invalid" would be false; calling it "fine" would miss
 * the actual problem. So a deviation is a WARNING - buildable, but not per
 * spec - exactly like the advisory inspection the user asked for in
 * procurement. The system reports; it does not silently block, and it does not
 * silently correct.
 */
final class PipingClassComplianceService
{
    public function __construct(
        private readonly PipeReference $reference,
        private readonly PipingClassRuleService $rules,
    ) {
    }

    /**
     * Check one component against the class of the line it sits on.
     *
     * Returns VALID when it matches, WARNING when it deviates or cannot be
     * judged, INVALID only when the component could not physically be built.
     */
    public function check(Component $component): Verdict
    {
        $line = $component->line;

        if ($line === null) {
            return Verdict::make(Verdict::WARNING, [
                ['code' => 'component.no_line', 'params' => []],
            ]);
        }

        $class = $line->pipingClass;

        if ($class === null) {
            // Not a defect in the component. The line has never been given a
            // class, so there is nothing to check against, and saying VALID
            // would imply an approval nobody gave.
            return Verdict::make(Verdict::WARNING, [
                ['code' => 'piping_class.not_set', 'params' => ['line' => (string) $line->line_number]],
            ]);
        }

        $size = (string) ($component->nominal_size ?? '');

        if ($size === '') {
            return Verdict::make(Verdict::WARNING, [
                ['code' => 'component.no_size', 'params' => []],
            ]);
        }

        try {
            $dn = $this->reference->size($size)->dn;
        } catch (UnknownSizeException) {
            // Physical layer: this size is not in ASME at all.
            return Verdict::make(Verdict::INVALID, [
                ['code' => 'size.unknown', 'params' => ['size' => $size]],
            ]);
        }

        $reasons = [];

        // --- schedule ----------------------------------------------------
        // Only components whose wall is set by a schedule. A valve has a
        // pressure class, not a schedule, and asking the question of one would
        // manufacture a deviation out of a category error.
        if ($this->isScheduled($component)) {
            $actual = $component->schedule;

            try {
                $expected = (string) $this->rules->resolve($class, PipingRuleKind::PIPE_SCHEDULE, $dn);

                if ($actual === null || $actual === '') {
                    $reasons[] = ['code' => 'schedule.missing', 'params' => [
                        'expected' => $expected, 'size' => $size,
                    ]];
                } elseif ($actual !== $expected) {
                    // Physical layer first: is the modelled schedule even real?
                    if (! $this->reference->hasSchedule($size, $actual)) {
                        return Verdict::make(Verdict::INVALID, [
                            ['code' => 'schedule.unknown', 'params' => [
                                'schedule' => $actual, 'size' => $size,
                            ]],
                        ]);
                    }

                    $reasons[] = ['code' => 'schedule.deviates', 'params' => [
                        'expected' => $expected, 'actual' => $actual, 'size' => $size,
                    ]];
                }
            } catch (NoRuleForSizeException) {
                // A hole in the PMS, not a fault in the component. Reported
                // with a distinct code so the two are never confused on a
                // report: one is fixed by the designer, the other by the
                // specification.
                $reasons[] = ['code' => 'piping_class.no_rule', 'params' => [
                    'class' => (string) $class->code,
                    'kind' => PipingRuleKind::PIPE_SCHEDULE,
                    'size' => $size,
                ]];
            }
        }

        return Verdict::make($reasons === [] ? Verdict::VALID : Verdict::WARNING, $reasons);
    }

    /**
     * Audit a whole line. This is the ADR-008 §5 promise in one call.
     *
     * @return array{checked:int,valid:int,deviations:int,invalid:int,findings:list<array{component_id:string,tag:string|null,template:string,size:string|null,state:string,reasons:list<array{code:string,params:array<string,scalar|null>}>}>}
     */
    public function auditLine(PipingLine $line): array
    {
        $checked = 0;
        $valid = 0;
        $deviations = 0;
        $invalid = 0;
        $findings = [];

        foreach ($line->components()->orderBy('sequence')->get() as $component) {
            $verdict = $this->check($component);
            $checked++;

            if ($verdict->state === Verdict::VALID) {
                $valid++;

                continue;
            }

            $verdict->state === Verdict::INVALID ? $invalid++ : $deviations++;

            $findings[] = [
                'component_id' => (string) $component->id,
                'tag' => $component->tag,
                'template' => (string) $component->template_code,
                'size' => $component->nominal_size,
                'state' => $verdict->state,
                'reasons' => $verdict->reasons,
            ];
        }

        return [
            'checked' => $checked,
            'valid' => $valid,
            'deviations' => $deviations,
            'invalid' => $invalid,
            'findings' => $findings,
        ];
    }

    /**
     * Is this component's wall set by a pipe schedule?
     *
     * Read from the reference pack rather than hard-coded here: the templates
     * already know which components are sized by the wall formula, and a second
     * list would drift from the first.
     */
    private function isScheduled(Component $component): bool
    {
        $code = (string) $component->template_code;

        try {
            $template = $this->reference->template($code);
        } catch (\Throwable) {
            return false;
        }

        // A rating and a schedule are alternative ways of specifying strength.
        // Anything that carries a pressure class is not sized by a schedule.
        if (($template['requiresRating'] ?? false) === true) {
            return false;
        }

        return in_array($template['category'] ?? '', ['PIPE', 'FITTING'], true);
    }
}
