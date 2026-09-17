<?php

declare(strict_types=1);

namespace App\Support\Piping;

use App\Models\Port;
use App\Support\Reference\PipeReference;

/**
 * Can these two ports be joined, and should anyone worry about it?
 * (ADR-006 section 5.4.)
 *
 * A pure function: no database, no locale, no side effects. That matters
 * because it is called on every attachment, every import row and every
 * re-validation of a line, and because a rule engine that can only be
 * exercised through a controller is a rule engine nobody tests properly.
 *
 * The split between refusing and warning is not arbitrary:
 *
 *   INVALID  - structurally unbuildable. A butt weld onto a flange face is not
 *              a thing a welder can do; no amount of engineering judgement
 *              makes it one.
 *   WARNING  - legal, common, and must be SEEN. Schedule 40 meeting schedule 80
 *              at equal bore is a spec break: correct at a boundary, a mistake
 *              in the middle of a run, and the system cannot tell which.
 *   VALID    - nothing to say.
 *
 * Note what is NOT checked here: whether the class's branch table wants a
 * weldolet instead of a tee. That is a DEVIATION against the piping class
 * (ADR-008 section 6), it needs the class data, and mixing it in would make
 * this function impure for the sake of one rule.
 */
final class PortCompatibility
{
    public function __construct(private readonly PipeReference $reference) {}

    public function evaluate(Port $a, Port $b): Verdict
    {
        $reasons = [];
        $fatal = false;

        // -------------------------------------------------------- identity --

        if ($a->id !== null && $a->id === $b->id) {
            // Only reachable from a bad import or a hand-written payload, but
            // it must not fall through to "equal bore, equal schedule, VALID".
            return Verdict::make(Verdict::INVALID, [
                ['code' => 'self_joint', 'params' => []],
            ]);
        }

        // ------------------------------------------------------------ size --

        $sizeA = $a->nominal_size_decimal;
        $sizeB = $b->nominal_size_decimal;

        if ($sizeA === null || $sizeB === null) {
            // An unsized port is not a small problem to be warned about: the
            // joint's frozen size comes from here, and a null would propagate
            // into dia-inch as a silent zero.
            $reasons[] = ['code' => 'size_unknown', 'params' => []];
            $fatal = true;
        } elseif (abs($sizeA - $sizeB) > 0.0001) {
            // Compared as floats with a tolerance, not with ===. 2.5 stored
            // through decimal(8,4) and 2.5 from the reference pack are the
            // same size and must not differ over a representation artefact.
            $reasons[] = ['code' => 'size_mismatch', 'params' => [
                'a' => self::trim($sizeA),
                'b' => self::trim($sizeB),
            ]];
            $fatal = true;
        }

        // -------------------------------------------------------- end type --

        $endA = $a->end_type;
        $endB = $b->end_type;

        if ($endA === null || $endB === null) {
            $reasons[] = ['code' => 'end_unknown', 'params' => []];
            $fatal = true;
        } else {
            $verdict = $this->evaluateEnds($endA, $endB);

            foreach ($verdict['reasons'] as $reason) {
                $reasons[] = $reason;
            }

            $fatal = $fatal || $verdict['fatal'];
        }

        // -------------------------------------------------------- schedule --

        // Only meaningful once the bores agree; complaining about wall
        // thickness on a 6-to-2 mismatch buries the real error under a
        // secondary one.
        if (! $fatal && $a->schedule !== null && $b->schedule !== null && $a->schedule !== $b->schedule) {
            $wallA = $this->wallOrNull($a);
            $wallB = $this->wallOrNull($b);

            // STD and SCH 40 are the same wall up to 10 inch, and a project
            // that writes one where the other is meant should not be nagged.
            // Comparing the resolved WALL rather than the schedule NAME is the
            // whole point - and above 10 inch they genuinely differ, so the
            // warning appears exactly where it should (ADR-007 section 2).
            if ($wallA === null || $wallB === null || abs($wallA - $wallB) > 0.0001) {
                $reasons[] = ['code' => 'schedule_mismatch', 'params' => [
                    'a' => $a->schedule,
                    'b' => $b->schedule,
                ]];
            }
        }

        if ($fatal) {
            return Verdict::make(Verdict::INVALID, $reasons);
        }

        return Verdict::make($reasons === [] ? Verdict::VALID : Verdict::WARNING, $reasons);
    }

    /**
     * End-preparation rules.
     *
     * @return array{reasons: list<array{code: string, params: array<string, scalar|null>}>, fatal: bool}
     */
    private function evaluateEnds(string $endA, string $endB): array
    {
        $reasons = [];

        // The wildcard is how the reference pack says "any end" - used by
        // fabricated specials and by import rows whose end is genuinely
        // unspecified. It agrees with everything by construction.
        if ($endA === '**' || $endB === '**') {
            return ['reasons' => [], 'fatal' => false];
        }

        $knownA = $this->isKnownEnd($endA);
        $knownB = $this->isKnownEnd($endB);

        if (! $knownA || $endB !== $endA && ! $knownB) {
            // An end code outside the vocabulary is refused rather than
            // ignored. A typo'd "BX" that quietly passes produces a joint that
            // claims to be checked and is not.
            $reasons[] = ['code' => 'end_unknown_code', 'params' => [
                'code' => $knownA ? $endB : $endA,
            ]];

            return ['reasons' => $reasons, 'fatal' => true];
        }

        if (! $knownB) {
            $reasons[] = ['code' => 'end_unknown_code', 'params' => ['code' => $endB]];

            return ['reasons' => $reasons, 'fatal' => true];
        }

        if ($endA === $endB) {
            // Matching ends are almost always right, with one exception: a
            // socket weld or a threaded end is gendered, and two sockets have
            // nothing to screw into each other. Gender is not modelled on the
            // port yet (PCF carries it as a MALE/FEMALE keypoint modifier), so
            // this is a warning rather than a refusal - refusing every SW/SW
            // pair would block the common, correct case.
            if ($this->isGendered($endA)) {
                $reasons[] = ['code' => 'gender_unverified', 'params' => ['end' => $endA]];
            }

            return ['reasons' => $reasons, 'fatal' => false];
        }

        // Plain end against a weldable end is how a field-cut pipe arrives.
        // The plain end gets bevelled on site; it is a real weld, not an error.
        $weldableA = $this->reference->isWeldable($endA);
        $weldableB = $this->reference->isWeldable($endB);

        if ($weldableA && $weldableB) {
            if ($endA === 'PL' || $endB === 'PL') {
                return ['reasons' => [], 'fatal' => false];
            }

            // BW to SW, BW to FA: different preparations that cannot be welded
            // to each other without a transition piece nobody has modelled.
            $reasons[] = ['code' => 'end_preparation_differs', 'params' => [
                'a' => $endA,
                'b' => $endB,
            ]];

            return ['reasons' => $reasons, 'fatal' => true];
        }

        // The canonical refusal: a bevelled pipe end against a flange face.
        $reasons[] = ['code' => 'end_mismatch', 'params' => [
            'a' => $endA,
            'b' => $endB,
        ]];

        return ['reasons' => $reasons, 'fatal' => true];
    }

    /**
     * Does this joint get a number?
     *
     * Numbering follows the END TYPES, not the joint's importance: a weld is
     * numbered because it will be radiographed and traced, a flange is not
     * because the bolt-up register owns it (ADR-006 section 11.2). Getting this
     * from the reference pack rather than a hardcoded list means a project that
     * adds an end type does not have to patch code.
     */
    public function isNumbered(Port $a, Port $b): bool
    {
        $endA = $a->end_type;
        $endB = $b->end_type;

        if ($endA === null || $endB === null) {
            return false;
        }

        // Both ends must agree that this is a numbered connection. A plain end
        // welded to a butt weld is numbered (PL is weldable); a butt weld
        // "against" a flange never gets here, it is INVALID.
        return $this->numberedOrWildcard($endA, $endB) && $this->numberedOrWildcard($endB, $endA);
    }

    /**
     * The joint's type, derived from the two ends and frozen onto the row.
     */
    public function jointType(Port $a, Port $b): string
    {
        $ends = array_filter([$a->end_type, $b->end_type], static fn (?string $e): bool => $e !== null && $e !== '**');

        if ($ends === []) {
            return 'UNKNOWN';
        }

        // Ordered by how much the type constrains downstream work: a joint
        // with one butt-welded end is a butt weld for scheduling, NDT and
        // dia-inch purposes regardless of what the other end is called.
        foreach (['BW', 'SW', 'FA', 'FL', 'SC'] as $code) {
            if (in_array($code, $ends, true)) {
                return match ($code) {
                    'BW' => 'BUTT_WELD',
                    'SW' => 'SOCKET_WELD',
                    'FA' => 'FABRICATED',
                    'FL' => 'FLANGED',
                    'SC' => 'SCREWED',
                };
            }
        }

        if (in_array('PL', $ends, true)) {
            return 'BUTT_WELD';
        }

        return 'OTHER';
    }

    /**
     * Dia-inch: the SMALLER diameter governs (ADR-010 section 1).
     *
     * The user's answer to the open question, and the difference is
     * commercial, not cosmetic: on the five-weld sample in ADR-010 the "larger
     * governs" reading overstates by 42%. On a job paid by dia-inch that is an
     * invoice.
     *
     * Computed, never stored - a stored copy drifts from the ports and the
     * error does not crash, it just inflates progress.
     */
    public function diaInch(Port $a, Port $b): ?float
    {
        $sizeA = $a->nominal_size_decimal;
        $sizeB = $b->nominal_size_decimal;

        if ($sizeA === null || $sizeB === null) {
            // Not zero. Zero is a real dia-inch value that sums silently into
            // a progress total; null forces the caller to decide.
            return null;
        }

        return min($sizeA, $sizeB);
    }

    // ------------------------------------------------------------ helpers --

    private function numberedOrWildcard(string $end, string $other): bool
    {
        if ($end === '**') {
            return false;
        }

        if ($end === 'PL') {
            // A plain end takes its numbering from what it is welded to.
            return $other !== 'PL' && $other !== '**' && $this->reference->isNumbered($other);
        }

        return $this->reference->isNumbered($end);
    }

    private function isKnownEnd(string $code): bool
    {
        try {
            $this->reference->endType($code);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function isGendered(string $code): bool
    {
        try {
            return (bool) ($this->reference->endType($code)['genderRequired'] ?? false);
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function wallOrNull(Port $port): ?float
    {
        if ($port->nominal_size === null || $port->schedule === null) {
            return null;
        }

        try {
            return $this->reference->wall($port->nominal_size, $port->schedule);
        } catch (\RuntimeException) {
            // An unrecognised schedule is not this function's error to raise -
            // it warns via schedule_mismatch instead of throwing mid-check.
            return null;
        }
    }

    private static function trim(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
}
