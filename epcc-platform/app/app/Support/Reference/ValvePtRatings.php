<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * Pressure–temperature ratings for VALVES — ASME B16.34-2025.
 *
 * WHY THIS IS NOT `FlangePtRatings`
 * ---------------------------------
 * Because a valve is not a flange, and the platform was quietly treating them
 * as one thing. `FlangePtRatings` holds B16.5 and B16.47, which rate FLANGES.
 * A valve's envelope comes from B16.34, and the two differ in three ways that
 * change answers rather than wording:
 *
 *   - **Special Class.** B16.34 gives a second, higher set of ratings for
 *     valves that have passed extra non-destructive examination. No flange
 *     standard has an equivalent, so nothing in the flange pack can produce it.
 *   - **Class 4500.** Exists here, in no flange standard this repo holds.
 *   - **No Class 400.** B16.5 has it; B16.34 does not tabulate it at all. A PMS
 *     line naming a Class 400 valve cannot be answered from this standard, and
 *     saying so is the correct behaviour — not falling back on the flange table.
 *
 * TWO WORDS SPELLED "CLASS"
 * -------------------------
 * PRESSURE class is 150/300/…/4500. RATING class is Standard / Special /
 * Limited and describes how much examination the valve has had. They are
 * independent axes and the method names here keep them apart on purpose.
 *
 * LIMITED CLASS IS NOT DATA
 * -------------------------
 * Mandatory Appendix V derives it per valve from a stress-area calculation, so
 * there is no table to hold. `ratingAt()` refuses it explicitly rather than
 * returning null as if the group were missing — a refusal with a reason.
 *
 * FOUR CELLS ARE WITHHELD
 * -----------------------
 * The transcription found four cells in B16.34-2025 whose printed value fails
 * arithmetic — digit transpositions, all in the highest classes. They are
 * stored **as printed**, because a transcription that silently corrects its
 * source is no longer a transcription, and they are listed in `anomalies` with
 * `doNotCite`. `ratingAt()` returns null for them and `anomalyAt()` explains
 * why. This is the `verified: false` principle applied one cell at a time:
 * hold the value, report it, never quote it.
 */
final class ValvePtRatings
{
    private const REF_ID = 'valve-pt-ratings';

    public const STANDARD = 'STANDARD';

    public const SPECIAL = 'SPECIAL';

    /** Computed per valve in Mandatory Appendix V; never tabulated. */
    public const LIMITED = 'LIMITED';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /**
     * The pressure classes B16.34 tabulates. Note what is NOT here: 400.
     *
     * @return list<string>
     */
    public function pressureClasses(): array
    {
        return $this->pack()['standard']['pressureClasses'];
    }

    public function tabulatesPressureClass(string $class): bool
    {
        return in_array($this->normaliseClass($class), $this->pressureClasses(), true);
    }

    /** @return list<string> */
    public function ratingClasses(): array
    {
        return $this->pack()['standard']['ratingClasses'];
    }

    /** @return list<string> */
    public function materialGroups(): array
    {
        $out = [];
        foreach ($this->pack()['curves'] as $c) {
            $out[$c['materialGroup']] = true;
        }

        return array_keys($out);
    }

    /**
     * Which B16.34 material group a spec and grade fall in, or null.
     *
     * Null is a real answer: B16.34 lists the materials a valve body may be
     * made of, and a spec absent from every group is one the standard does not
     * cover. Falling back on the flange standard's grouping would be a guess
     * dressed as an answer, because the two standards' group lists differ —
     * B16.34 has 50 groups where B16.5 has 45.
     */
    public function groupFor(string $spec, ?string $grade = null): ?string
    {
        $spec = strtoupper(trim($spec));
        $grade = $grade === null ? null : $this->normaliseGrade($grade);

        foreach ($this->pack()['curves'] as $c) {
            if ($c['ratingClass'] !== self::STANDARD) {
                continue;
            }
            foreach ($c['materials'] as $m) {
                if (strtoupper($m['spec']) !== $spec) {
                    continue;
                }
                $mg = $m['grade'] === null ? null : $this->normaliseGrade($m['grade']);
                if ($grade !== null && $mg === $grade) {
                    return $c['materialGroup'];
                }
                if ($grade === null && $mg === null) {
                    return $c['materialGroup'];
                }
            }
        }

        // A spec whose grade did not match still narrows things down, but only
        // when the spec sits in exactly ONE group. A105 does. A182 is spread
        // over a dozen groups from F1 to F91, and returning the first one found
        // would be a lie with a plausible shape - the reader would get group
        // 1.5 for an A182 F316 valve and never know.
        return $this->soleGroupForSpec($spec);
    }

    /**
     * Does B16.34 list this spec anywhere, whatever the grade?
     *
     * The distinction `groupFor()` cannot make on its own: a null from that
     * method means EITHER the standard has never heard of the spec OR it has,
     * several times, and the grade was not given. The first is a discrepancy
     * in the document; the second is an unfinished line. Reporting them the
     * same way tells a transcriber to go and check a spec that is perfectly
     * correct.
     */
    public function listsSpec(string $spec): bool
    {
        $spec = strtoupper(trim($spec));
        foreach ($this->pack()['curves'] as $c) {
            foreach ($c['materials'] as $m) {
                if (strtoupper($m['spec']) === $spec) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The grades B16.34 lists under a spec, with the group each falls in.
     * A216 gives WCB in group 1.1 and WCC in 1.2 - two rating curves behind
     * one spec number, which is exactly why a grade is not optional.
     *
     * @return list<array{grade:string|null,materialGroup:string}>
     */
    public function gradesOfSpec(string $spec): array
    {
        $spec = strtoupper(trim($spec));
        $out = [];
        foreach ($this->pack()['curves'] as $c) {
            if ($c['ratingClass'] !== self::STANDARD) {
                continue;
            }
            foreach ($c['materials'] as $m) {
                if (strtoupper($m['spec']) === $spec) {
                    $out[($m['grade'] ?? '').'|'.$c['materialGroup']] = [
                        'grade' => $m['grade'],
                        'materialGroup' => $c['materialGroup'],
                    ];
                }
            }
        }

        return array_values($out);
    }

    /**
     * The working pressure in kPa at a temperature, or null.
     *
     * Null means one of four things, and `refusalFor()` says which: the group
     * is unknown, the pressure class is not tabulated, the temperature is off
     * the end of the curve, or the cell is a declared anomaly. None of them is
     * "assume the flange value".
     *
     * Between two tabulated temperatures the value is interpolated linearly,
     * which is what B16.34 para. 2.1.1 permits. Below the first temperature the
     * first row applies; above the last there is no rating at all.
     */
    public function ratingAt(
        string $materialGroup,
        string $pressureClass,
        float $temperatureC,
        string $ratingClass = self::STANDARD,
    ): ?int {
        $class = $this->normaliseClass($pressureClass);
        $curve = $this->curve($materialGroup, $ratingClass);
        if ($curve === null || ! $this->tabulatesPressureClass($class)) {
            return null;
        }

        $pts = $curve['points'];
        $first = $pts[0];
        $last = $pts[count($pts) - 1];

        if ($temperatureC > (float) $last['temperatureC']) {
            return null;
        }
        if ($temperatureC <= (float) $first['temperatureC']) {
            return $this->cell($materialGroup, $ratingClass, $first, $class);
        }

        foreach ($pts as $i => $p) {
            if ((float) $p['temperatureC'] === $temperatureC) {
                return $this->cell($materialGroup, $ratingClass, $p, $class);
            }
            if ((float) $p['temperatureC'] > $temperatureC) {
                $lo = $pts[$i - 1];
                $hi = $p;
                $a = $this->cell($materialGroup, $ratingClass, $lo, $class);
                $b = $this->cell($materialGroup, $ratingClass, $hi, $class);
                if ($a === null || $b === null) {
                    return null;   // an anomaly at either end poisons the span
                }
                $span = (float) $hi['temperatureC'] - (float) $lo['temperatureC'];
                $f = ($temperatureC - (float) $lo['temperatureC']) / $span;

                return (int) round($a + ($b - $a) * $f);
            }
        }

        return null;
    }

    /**
     * Why `ratingAt()` said null. A null answer a caller cannot explain to a
     * transcriber is worse than no answer at all.
     */
    public function refusalFor(
        string $materialGroup,
        string $pressureClass,
        float $temperatureC,
        string $ratingClass = self::STANDARD,
    ): ?string {
        $class = $this->normaliseClass($pressureClass);

        if ($ratingClass === self::LIMITED) {
            return 'LIMITED_CLASS_IS_COMPUTED';
        }
        if ($this->curve($materialGroup, $ratingClass) === null) {
            return 'MATERIAL_GROUP_UNKNOWN';
        }
        if (! $this->tabulatesPressureClass($class)) {
            return 'PRESSURE_CLASS_NOT_TABULATED';
        }
        if ($this->anomalyAt($materialGroup, $class, $temperatureC, $ratingClass) !== null) {
            return 'CELL_WITHHELD_AS_SUSPECTED_PRINTING_ERROR';
        }

        $pts = $this->curve($materialGroup, $ratingClass)['points'];
        if ($temperatureC > (float) $pts[count($pts) - 1]['temperatureC']) {
            return 'ABOVE_THE_LAST_TABULATED_TEMPERATURE';
        }

        return null;
    }

    /**
     * The declared anomaly covering this cell, if any.
     *
     * @return array<string,mixed>|null
     */
    public function anomalyAt(
        string $materialGroup,
        string $pressureClass,
        float $temperatureC,
        string $ratingClass = self::STANDARD,
    ): ?array {
        $class = $this->normaliseClass($pressureClass);
        foreach ($this->pack()['anomalies'] ?? [] as $a) {
            if ($a['materialGroup'] === $materialGroup
                && $a['ratingClass'] === $ratingClass
                && $a['pressureClass'] === $class
                && (float) $a['temperatureC'] === $temperatureC) {
                return $a;
            }
        }

        return null;
    }

    /**
     * The SEAT rating of a soft-seated ball valve, in kPa — API 608 Table 1.
     *
     * A valve is not its shell. API 608 clause 4.1: *"The valve
     * pressure-temperature rating shall be the lesser of the shell rating or
     * the seat and seal rating."* For a PTFE seat the seat wins, and not
     * narrowly: a Class 600 group 1.1 shell is 102 bar at 38 °C, and the same
     * valve with a PTFE seat above DN 100 is **19.7 bar**.
     *
     * Null where the standard says nothing — above 177 °C for PTFE, and for a
     * METAL seat, which Table 1 does not cover at all. Null is "not limited by
     * this table", never "unlimited".
     */
    public function seatRatingAt(
        string $valveType,
        string $seatMaterial,
        float $temperatureC,
        array $discriminators = [],
        ?int $dn = null,
        ?string $pressureClass = null,
    ): ?int {
        $type = strtoupper(trim($valveType));
        $seat = strtoupper(str_replace('-', '', trim($seatMaterial)));
        $class = $pressureClass === null
            ? null
            : (string) preg_replace('/[^0-9]/', '', $pressureClass);

        foreach ($this->pack()['seatRatings'] ?? [] as $c) {
            if ($c['valveType'] !== $type || $c['seatMaterial'] !== $seat) {
                continue;
            }
            // TWO STANDARDS, TWO AXES. API 608 keys a ball seat on the ball
            // design and a DN band; API 609 keys a butterfly seat on the
            // pressure class. The curve carries whichever discriminators its
            // own standard uses, and a caller that does not supply them gets
            // no answer rather than the first curve that happens to match.
            foreach ($c['discriminators'] as $k => $want) {
                if (($discriminators[$k] ?? null) !== $want) {
                    continue 2;
                }
            }
            if ($c['pressureClass'] !== null && $c['pressureClass'] !== $class) {
                continue;
            }
            if ($dn !== null && $c['dnAboveExclusive'] !== null && $dn <= $c['dnAboveExclusive']) {
                continue;
            }
            if ($dn !== null && $c['dnUpToInclusive'] !== null && $dn > $c['dnUpToInclusive']) {
                continue;
            }
            if ($dn === null && ($c['dnAboveExclusive'] !== null || $c['dnUpToInclusive'] !== null)) {
                continue;   // the curve needs a size and none was given
            }

            return $this->interpolate($c['points'], $temperatureC);
        }

        return null;
    }

    /**
     * The rating the valve actually has: the lesser of shell and seat.
     *
     * The method the platform should have had from the start. Returns the
     * shell figure when nothing limits the seat, so a metal-seated valve is
     * unaffected.
     *
     * @return array{kPa:int|null,governedBy:string}
     */
    public function governingRatingAt(
        string $materialGroup,
        string $pressureClass,
        float $temperatureC,
        ?string $seatMaterial = null,
        string $valveType = 'BALL',
        array $discriminators = [],
        ?int $dn = null,
    ): array {
        $shell = $this->ratingAt($materialGroup, $pressureClass, $temperatureC);

        if ($seatMaterial === null || $discriminators === []) {
            return ['kPa' => $shell, 'governedBy' => 'SHELL'];
        }

        $seat = $this->seatRatingAt(
            $valveType, $seatMaterial, $temperatureC,
            $discriminators, $dn, $pressureClass,
        );
        if ($seat === null) {
            return ['kPa' => $shell, 'governedBy' => 'SHELL'];
        }
        if ($shell === null) {
            return ['kPa' => $seat, 'governedBy' => 'SEAT'];
        }

        return $seat < $shell
            ? ['kPa' => $seat, 'governedBy' => 'SEAT']
            : ['kPa' => $shell, 'governedBy' => 'SHELL'];
    }

    /**
     * @param  list<array{temperatureC:int,barG:float}>  $points
     */
    private function interpolate(array $points, float $t): ?int
    {
        $first = $points[0];
        $last = $points[count($points) - 1];

        if ($t > (float) $last['temperatureC']) {
            return null;   // past the end of the seat curve: no rating at all
        }
        if ($t <= (float) $first['temperatureC']) {
            return (int) round($first['barG'] * 100);
        }

        foreach ($points as $i => $p) {
            if ((float) $p['temperatureC'] >= $t) {
                $lo = $points[$i - 1];
                $span = (float) $p['temperatureC'] - (float) $lo['temperatureC'];
                $f = ($t - (float) $lo['temperatureC']) / $span;

                return (int) round(($lo['barG'] + ($p['barG'] - $lo['barG']) * $f) * 100);
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function seatRatingRule(): array
    {
        return $this->pack()['seatRatingRule'] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function anomalies(): array
    {
        return $this->pack()['anomalies'] ?? [];
    }

    /**
     * How far apart B16.34 Standard Class and B16.5 are for the same group.
     * Eleven cells of 4,050; the list is the answer to "can I use the flange
     * table for a valve", which is "almost, and almost is not a rating".
     *
     * @return list<array<string,mixed>>
     */
    public function crossStandardDiscrepancies(): array
    {
        return $this->pack()['crossStandardDiscrepancies'] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function observations(): array
    {
        return $this->pack()['observations'] ?? [];
    }

    /**
     * The notes attached to a group's table — "not to be used over 371 C" and
     * the like. These bound a material inside a curve that still prints
     * numbers, so a consumer that ignores them over-rates the valve.
     *
     * @return list<array{ref:int,text:string}>
     */
    public function notesFor(string $materialGroup, string $ratingClass = self::STANDARD): array
    {
        return $this->curve($materialGroup, $ratingClass)['notes'] ?? [];
    }

    /**
     * The notes that apply to ONE material inside its group.
     *
     * @return list<array{ref:int,text:string}>
     */
    public function notesForMaterial(string $spec, ?string $grade = null): array
    {
        $group = $this->groupFor($spec, $grade);
        if ($group === null) {
            return [];
        }
        $curve = $this->curve($group, self::STANDARD);
        $spec = strtoupper(trim($spec));
        $refs = [];
        foreach ($curve['materials'] as $m) {
            if (strtoupper($m['spec']) !== $spec) {
                continue;
            }
            if ($grade === null
                || ($m['grade'] !== null
                    && $this->normaliseGrade($m['grade']) === $this->normaliseGrade($grade))) {
                $refs = array_merge($refs, $m['noteRefs']);
            }
        }
        $refs = array_unique($refs);

        return array_values(array_filter(
            $curve['notes'] ?? [],
            static fn (array $n): bool => in_array($n['ref'], $refs, true),
        ));
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    // ---------------------------------------------------------- internals --

    /** @return array<string,mixed>|null */
    private function curve(string $materialGroup, string $ratingClass): ?array
    {
        foreach ($this->pack()['curves'] as $c) {
            if ($c['materialGroup'] === $materialGroup && $c['ratingClass'] === $ratingClass) {
                return $c;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $point */
    private function cell(string $group, string $ratingClass, array $point, string $class): ?int
    {
        if ($this->anomalyAt($group, $class, (float) $point['temperatureC'], $ratingClass) !== null) {
            return null;
        }

        return isset($point['byClass'][$class]) ? (int) $point['byClass'][$class] : null;
    }

    private function soleGroupForSpec(string $spec): ?string
    {
        $found = [];
        foreach ($this->pack()['curves'] as $c) {
            if ($c['ratingClass'] !== self::STANDARD) {
                continue;
            }
            foreach ($c['materials'] as $m) {
                if (strtoupper($m['spec']) === $spec) {
                    $found[$c['materialGroup']] = true;
                }
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }

    /** '150#', 'Class 150', '150' all mean the same pressure class. */
    private function normaliseClass(string $class): string
    {
        return (string) preg_replace('/[^0-9]/', '', $class);
    }

    /** 'Cl. 1', 'CL1', 'cl 1' and 'Gr. WCB' vs 'WCB' must not be three answers. */
    private function normaliseGrade(string $grade): string
    {
        $g = strtoupper(trim($grade));
        $g = (string) preg_replace('/^GR\.?\s*/', '', $g);

        return (string) preg_replace('/[^A-Z0-9]/', '', $g);
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/'.self::REF_ID.'.ref.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Reference pack not readable: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Reference pack is not valid JSON: {$path}");
        }

        return $this->pack = $decoded;
    }
}
