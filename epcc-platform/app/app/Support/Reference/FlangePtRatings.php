<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * ASME B16.5 / B16.47 pressure-temperature ratings.
 *
 * WHAT THIS ANSWERS
 * -----------------
 * The question a piping class exists to answer: at this design temperature,
 * how much pressure may this flange hold? It is the second hop of a two-hop
 * lookup that ADR-008 section 11 established:
 *
 *      grade  --(MaterialLibrary::b165MaterialGroup)-->  group
 *      (standard, edition, group, class, temperature)  -->  pressure
 *
 * THIS CLASS NOW ANSWERS (2026-09-24)
 * -----------------------------------
 * It used to refuse everything. Every figure had been transcribed from public
 * sources that contradict each other - five of them disagreed about which
 * GROUP a material even belongs to - so `ratingAt()` returned null and
 * `indicativeRatingAt()` existed for screens willing to show an unchecked
 * number in a warning colour.
 *
 * The project owner then supplied a purchased copy of ASME B16.5-2025, and all
 * 45 SI tables (2-1.1 through 2-3.19) were transcribed from it: 675
 * temperature points, 7 classes, 4,725 values. Those curves carry
 * `verified: true` and a `sourceTable`, and this class answers for them.
 *
 * NOT ONE LINE OF THIS CLASS CHANGED to make that happen. The refusal was data,
 * not code - which is the whole point of holding the input and never
 * pre-computing a conclusion you cannot defend.
 *
 * B16.47 is still unverified, has no curves, and is still refused. The split
 * between ratingAt() and indicativeRatingAt() therefore stays exactly as it is.
 *
 * WHAT THE READING COST US TO LEARN
 * ---------------------------------
 * The old Group 1.1 curve was a confident reconstruction. Measured against the
 * real table it was 0.42 per cent out on average and 2.61 per cent at worst,
 * carried 6 of the 16 tabulated temperatures, and had omitted Class 400
 * altogether. Good enough to look right in a review; not good enough to buy
 * steel against.
 *
 * THREE TRAPS THIS CLASS EXISTS TO AVOID
 * --------------------------------------
 *   1. A CLASS NUMBER IS NOT A PRESSURE. Class 300 is not 300 psi and cannot
 *      be compared with B16.11's Class 3000 or API 6A's 3000 psi. Sufficiency
 *      is decided on pressure at temperature, never on the label. That is why
 *      `sufficientFor()` takes a pressure and a temperature and nothing else.
 *   2. SOME CLASSES ARE FORMULAE. API 602's Class 800 is not tabulated by
 *      anyone; it is one third of Class 600 plus two thirds of Class 900.
 *      `isDerived()` says so and the lookup computes it.
 *   3. B16.47 STOPS AT CLASS 900 AND B16.5 STOPS AT NPS 24. A 1500 class
 *      cannot carry a 30 inch flange. `maxClassFor()` is how a caller asks.
 *
 * Interpolation is linear between tabulated temperatures, which B16.5 permits.
 * Extrapolation beyond either end is refused, which B16.5 requires. Note the
 * points here are sparser than the standard's own tabulation, so interpolating
 * them is coarser than interpolating B16.5 - another reason for the refusal.
 */
final class FlangePtRatings
{
    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir) {}

    // --------------------------------------------------------- the lookup --

    /**
     * Maximum allowable working gauge pressure in kPa, or null.
     *
     * Null means one of: the curve is not verified, the standard or group or
     * class is unknown, or the temperature is outside the tabulated range.
     * A caller MUST treat all of those as "cannot say" and none of them as a
     * default or a zero.
     */
    public function ratingAt(
        string $standard,
        string $materialGroup,
        string $class,
        float $temperatureC,
    ): ?int {
        $curve = $this->curve($standard, $materialGroup);

        if ($curve === null || $curve['verified'] !== true) {
            return null;
        }

        return $this->compute($curve, $class, $temperatureC);
    }

    /**
     * The same number, returned even when the curve is unverified.
     *
     * For DISPLAY ONLY, and only where the screen says so. Never feed this to
     * a compliance check: a finding computed from an unchecked table is worse
     * than no finding, because someone will act on it.
     */
    public function indicativeRatingAt(
        string $standard,
        string $materialGroup,
        string $class,
        float $temperatureC,
    ): ?int {
        $curve = $this->curve($standard, $materialGroup);

        return $curve === null ? null : $this->compute($curve, $class, $temperatureC);
    }

    /** Is this curve checked against a purchased standard yet? */
    public function isVerified(string $standard, string $materialGroup): bool
    {
        return ($this->curve($standard, $materialGroup)['verified'] ?? false) === true;
    }

    /**
     * Does a class hold the design condition?
     *
     * Deliberately takes a PRESSURE and a TEMPERATURE. There is no variant
     * that compares two class numbers, because comparing class numbers across
     * standards is the error this whole area keeps producing.
     *
     * Null when the rating is unknown - which is NOT the same as false, and a
     * caller that treats it as false will report a compliant line as a
     * violation.
     */
    public function sufficientFor(
        string $standard,
        string $materialGroup,
        string $class,
        float $designPressureKpa,
        float $temperatureC,
    ): ?bool {
        $rated = $this->ratingAt($standard, $materialGroup, $class, $temperatureC);

        return $rated === null ? null : $rated >= $designPressureKpa;
    }

    // ------------------------------------------------------------ the map --

    /** @return list<string> */
    public function classesFor(string $standard): array
    {
        foreach ($this->pack()['standards'] as $s) {
            if ($s['code'] === $standard) {
                return $s['classes'];
            }
        }

        return [];
    }

    /**
     * The highest class a standard defines, as an integer.
     *
     * B16.5 reaches 2500; B16.47 stops at 900. Nothing in the system currently
     * stops a 1500 class from being written against a 30 inch flange, and this
     * is what a check would ask. ADR-008 section 11.15.
     */
    public function maxClassFor(string $standard): ?int
    {
        $classes = array_map('intval', $this->classesFor($standard));

        return $classes === [] ? null : max($classes);
    }

    /**
     * The classes a DIMENSIONAL SERIES offers, not just the standard.
     *
     * B16.47 Class 75 exists in Series B alone: B16.47-2025 tabulates its
     * dimensions under Series B (Table 37) while Series A starts at Class 150
     * (Table 32). A Series A line calling for Class 75 is asking for a flange
     * nobody makes, and `definesClass()` is too coarse to notice.
     *
     * Null - not an empty list - when the standard has no series axis, because
     * "this standard does not work that way" is not the same answer as "this
     * series offers nothing".
     *
     * @return list<string>|null
     */
    public function classesForSeries(string $standard, string $series): ?array
    {
        foreach ($this->pack()['standards'] as $s) {
            if ($s['code'] !== $standard) {
                continue;
            }
            if (! isset($s['classesBySeries'])) {
                return null;
            }

            return $s['classesBySeries'][$series] ?? [];
        }

        return null;
    }

    /**
     * Do the dimensional series of this standard share one rating table?
     *
     * For B16.47 the answer is yes, and it is VERIFIED: the 27 rating tables
     * are indexed by material group with no series axis at all, while the
     * dimension tables are per series. Series A and B differ in bolt circle and
     * bolt count, not in what pressure they hold. They are still NOT
     * interchangeable at the same NPS and class - that is a dimensional fact,
     * not a rating one.
     */
    public function seriesShareRatings(string $standard): bool
    {
        foreach ($this->pack()['standards'] as $s) {
            if ($s['code'] === $standard) {
                return ($s['seriesShareRatings'] ?? false) === true;
            }
        }

        return false;
    }

    /**
     * Does a sister standard rate this exact cell differently?
     *
     * B16.5-2025 and B16.47-2025 rate the same material groups and agree on
     * 2,089 of the 2,100 values they share. These are the other 11. The caller
     * still gets the figure from the standard it asked for - that one is
     * contractual - but it can now find out that the other document disagrees
     * instead of discovering it in a vendor's rejection letter.
     *
     * Null means the two standards agree, or only one of them rates this cell.
     *
     * @return array<string,mixed>|null
     */
    public function discrepancyFor(
        string $materialGroup,
        string $class,
        int $temperatureC,
    ): ?array {
        foreach ($this->pack()['crossStandardDiscrepancies']['cells'] ?? [] as $cell) {
            if ($cell['materialGroup'] === $materialGroup
                && $cell['class'] === $class
                && $cell['temperatureC'] === $temperatureC) {
                return $cell;
            }
        }

        return null;
    }

    public function definesClass(string $standard, string $class): bool
    {
        return in_array($class, $this->classesFor($standard), true);
    }

    /** Is this class a formula rather than a table? Class 800 is. */
    public function isDerived(string $class): bool
    {
        return $this->derivation($class) !== null;
    }

    /**
     * How a derived class is built, for a screen that has to explain itself.
     *
     * @return array{class:string,definedBy:string,derivedFrom:list<array{class:string,weight:float}>,note:string}|null
     */
    public function derivation(string $class): ?array
    {
        foreach ($this->pack()['derivedClasses'] as $d) {
            if ($d['class'] === $class) {
                return [
                    'class' => $d['class'],
                    'definedBy' => $d['definedBy'],
                    'derivedFrom' => $d['derivedFrom'],
                    'note' => $d['note'],
                ];
            }
        }

        return null;
    }

    /** Which material groups have a curve at all. @return list<string> */
    public function groupsWithCurves(string $standard): array
    {
        $out = [];
        foreach ($this->pack()['curves'] as $c) {
            if ($c['standard'] === $standard) {
                $out[] = $c['materialGroup'];
            }
        }

        return $out;
    }

    /**
     * The edition a curve was transcribed from.
     *
     * Not decoration. Three findings in one day turned on an edition: the
     * few-psi drift between B16.5 and B16.47, B16.11's correlation table
     * moving from Table 7 to Table 8, and API 6D restating Class 600 at 100 F
     * from 1440 to 1480 psi. A citation without an edition is not a citation.
     */
    public function editionYear(string $standard, string $materialGroup): ?int
    {
        return $this->curve($standard, $materialGroup)['editionYear'] ?? null;
    }

    // ------------------------------------------------------------ private --

    /** @param array<string,mixed> $curve */
    private function compute(array $curve, string $class, float $temperatureC): ?int
    {
        $derivation = $this->derivation($class);

        if ($derivation !== null) {
            $total = 0.0;
            foreach ($derivation['derivedFrom'] as $part) {
                $component = $this->tabulated($curve, $part['class'], $temperatureC);
                if ($component === null) {
                    return null; // a formula is only as available as its inputs
                }
                $total += $component * $part['weight'];
            }

            return (int) round($total);
        }

        return $this->tabulated($curve, $class, $temperatureC);
    }

    /** @param array<string,mixed> $curve */
    private function tabulated(array $curve, string $class, float $temperatureC): ?int
    {
        $points = [];
        foreach ($curve['points'] as $p) {
            if (isset($p['byClass'][$class])) {
                $points[] = [(float) $p['temperatureC'], (int) $p['byClass'][$class]];
            }
        }

        if ($points === []) {
            return null;
        }

        usort($points, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // B16.5 forbids extrapolation. Silence beyond the ends of the table is
        // the only honest answer; a caller wanting 600 C from a carbon steel
        // curve has a design problem, not a lookup problem.
        if ($temperatureC < $points[0][0] || $temperatureC > $points[count($points) - 1][0]) {
            return null;
        }

        foreach ($points as [$t, $v]) {
            if (abs($t - $temperatureC) < 1e-9) {
                return $v;
            }
        }

        for ($i = 0; $i < count($points) - 1; $i++) {
            [$t0, $v0] = $points[$i];
            [$t1, $v1] = $points[$i + 1];

            if ($temperatureC > $t0 && $temperatureC < $t1) {
                $fraction = ($temperatureC - $t0) / ($t1 - $t0);

                return (int) round($v0 + ($v1 - $v0) * $fraction);
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function curve(string $standard, string $materialGroup): ?array
    {
        foreach ($this->pack()['curves'] as $c) {
            if ($c['standard'] === $standard && $c['materialGroup'] === $materialGroup) {
                return $c;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/flange-pt-ratings.ref.json';
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
