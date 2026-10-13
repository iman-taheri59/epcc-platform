<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * What has to be witnessed before a valve is accepted — API Standard 598 (2023).
 *
 * The third leg of the valve. B16.34 rates it, B16.10 measures it, and API 598
 * says which tests, at what pressure, for how long, and how much leakage is
 * allowed. It is the first reference in the platform that produces **ITP rows**
 * rather than drawing figures, which is why it exists: the valve ITP could not
 * be written without it.
 *
 * THE SHELL TEST PRESSURE IS NOT IN API 598
 * -----------------------------------------
 * For a steel valve, Table 2 gives no number. It gives a rule — one and a half
 * times the pressure rating at 38 °C, rounded up to the next whole bar — and
 * the rating lives in ASME B16.34. So `shellTestPressureBarG()` takes
 * `ValvePtRatings` and computes it. That is the first calculation in the
 * platform that needs two purchased standards at once, and it is exactly the
 * figure a vendor's test certificate states and nobody re-derives.
 *
 * TWO AXES THE PMS DOES NOT HAVE
 * ------------------------------
 * API 598 tests a **floating** ball valve differently from a **trunnion
 * mounted** one, and an API 609 **Category A** butterfly differently from a
 * **Category B**. A PMS row says "BALL" and stops. Rather than pick one,
 * `testsFor()` refuses and `refusalFor()` returns `VARIANT_REQUIRED` naming the
 * choices — a gap in our model made visible instead of guessed past.
 *
 * READ TABLE 1 AS A PAIR
 * ----------------------
 * Wherever the low-pressure closure test becomes optional — above Class 1500
 * for valves DN 100 and below, above Class 600 for larger ones — the
 * high-pressure closure test becomes required. Note the threshold **moves with
 * the size**: treating it as a single class boundary mis-states every large
 * valve between Class 600 and Class 1500.
 */
final class ValveTesting
{
    private const REF_ID = 'valve-testing';

    public const SHELL = 'SHELL';

    public const BACKSEAT = 'BACKSEAT';

    public const LOW_PRESSURE_CLOSURE = 'LOW_PRESSURE_CLOSURE';

    public const HIGH_PRESSURE_CLOSURE = 'HIGH_PRESSURE_CLOSURE';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(
        private readonly string $refDir,
        private readonly ValvePtRatings $ratings,
    ) {
    }

    /**
     * The variants API 598 distinguishes for a valve type, or [] when it makes
     * no distinction. A caller that gets a non-empty list must choose.
     *
     * @return list<string>
     */
    public function variantsFor(string $valveType): array
    {
        $type = strtoupper(trim($valveType));
        $out = [];
        foreach ($this->pack()['columns'] as $col) {
            foreach (array_merge([$col], $col['alsoCovers'] ?? []) as $c) {
                if ($c['valveType'] === $type && ($c['variant'] ?? null) !== null) {
                    $out[$c['variant']] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Must the caller name a variant before this valve type can be answered?
     *
     * True only when API 598 gives the bare type NO column of its own. A gate
     * valve has one, and "parallel slide gate" is an extra route into the globe
     * column - so GATE has a variant but does not require one. A ball valve has
     * no plain column at all: floating and trunnion mounted are tested
     * differently and there is no third answer.
     */
    public function requiresVariant(string $valveType): bool
    {
        $type = strtoupper(trim($valveType));
        foreach ($this->pack()['columns'] as $col) {
            foreach (array_merge([$col], $col['alsoCovers'] ?? []) as $c) {
                if ($c['valveType'] === $type && ($c['variant'] ?? null) === null) {
                    return false;
                }
            }
        }

        return $this->variantsFor($type) !== [];
    }

    /**
     * The tests API 598 puts on one valve, each with its pressure, duration and
     * allowable leakage — an ITP in reference-data form.
     *
     * @return list<array{test:string,requirement:string,durationSeconds:int,
     *                    pressureBarG:float|null,pressureRule:string|null,
     *                    leakage:array<string,mixed>|null,noteRefs:list<string>,
     *                    notes:list<string>}>
     */
    public function testsFor(
        string $valveType,
        int $dn,
        string $pressureClass,
        ?string $variant = null,
        string $seat = 'METAL',
        ?string $materialGroup = null,
    ): array {
        $column = $this->columnFor($valveType, $variant);
        if ($column === null) {
            return [];
        }

        $class = (int) preg_replace('/[^0-9]/', '', $pressureClass);
        $out = [];

        foreach ($this->pack()['requirements'] as $r) {
            if ($r['column'] !== $column) {
                continue;
            }
            if (! $this->inSizeBand($dn, $r['sizeBand'])) {
                continue;
            }
            if (! $this->inClassBand($class, $r['classBand'])) {
                continue;
            }
            if ($r['requirement'] === 'NOT_APPLICABLE') {
                continue;
            }

            $out[] = [
                'test' => $r['test'],
                'requirement' => $r['requirement'],
                'durationSeconds' => $this->durationSeconds($r['test'], $dn, $valveType),
                'pressureBarG' => $this->pressureBarG(
                    $r['test'], $valveType, $pressureClass, $materialGroup,
                ),
                'pressureRule' => $this->pressureRule($r['test'], $valveType),
                'leakage' => $r['test'] === self::LOW_PRESSURE_CLOSURE
                    || $r['test'] === self::HIGH_PRESSURE_CLOSURE
                        ? $this->leakageFor($dn, $valveType, $seat)
                        : null,
                'noteRefs' => $r['noteRefs'],
                'notes' => array_values(array_filter(array_map(
                    fn (string $ref): ?string => $this->pack()['requirementNotes'][$ref] ?? null,
                    $r['noteRefs'],
                ))),
            ];
        }

        return $out;
    }

    /** Why `testsFor()` returned nothing. */
    public function refusalFor(string $valveType, ?string $variant = null): ?string
    {
        $type = strtoupper(trim($valveType));
        if ($variant === null && $this->requiresVariant($type)) {
            return 'VARIANT_REQUIRED';
        }
        if ($this->columnFor($type, $variant) === null) {
            return $this->variantsFor($type) !== []
                ? 'VARIANT_UNKNOWN'
                : 'VALVE_TYPE_NOT_IN_API598';
        }

        return null;
    }

    /**
     * The hydrostatic shell test pressure, in bar gauge.
     *
     * For steel this is not tabulated anywhere: it is 1.5 × the ASME B16.34
     * rating at 38 °C, rounded UP to the next whole bar. Null when the material
     * group is unknown, because the rating cannot be looked up without it —
     * and a shell test pressure guessed from the class alone would be wrong for
     * every group but one.
     */
    public function shellTestPressureBarG(string $pressureClass, ?string $materialGroup): ?float
    {
        if ($materialGroup === null) {
            return null;
        }

        $rule = $this->pack()['shellTestRule'];
        $kpa = $this->ratings->ratingAt(
            $materialGroup,
            $pressureClass,
            (float) $rule['ratingTemperatureC'],
        );
        if ($kpa === null) {
            return null;
        }

        // kPa -> bar, times the multiplier, then up to the next whole bar.
        return (float) ceil($kpa / 100 * $rule['multiplier']);
    }

    /**
     * The minimum hold time in seconds. A check valve holds a closure test for
     * a different time from everything else, which is why the valve type is
     * needed for what looks like a size lookup.
     */
    public function durationSeconds(string $test, int $dn, string $valveType = 'GATE'): int
    {
        $band = $this->durationBand($dn);

        return match ($test) {
            self::SHELL => (int) $band['shell'],
            self::BACKSEAT => (int) $band['backseat'],
            default => strtoupper(trim($valveType)) === 'CHECK'
                ? (int) $band['closureCheck']
                : (int) $band['closureOther'],
        };
    }

    /**
     * The allowable closure leakage for a size, seat and valve type.
     *
     * Above DN 1200 the standard stops tabulating and gives formulas; they are
     * applied here rather than the table being extrapolated, which would be a
     * different and wrong answer.
     *
     * @return array<string,mixed>
     */
    public function leakageFor(int $dn, string $valveType = 'GATE', string $seat = 'METAL'): array
    {
        $isCheck = strtoupper(trim($valveType)) === 'CHECK';
        $resilient = strtoupper(trim($seat)) !== 'METAL';

        if ($resilient) {
            // Not a rate that grows with size: a resilient seat is defined by
            // sealing completely. Zero at every size, by definition.
            return ['seat' => 'RESILIENT', 'dropsPerMin' => 0.0, 'bubblesPerMin' => 0.0,
                    'basis' => 'TABLE', 'zeroMeans' => $this->pack()['leakage'][0]['noteRefs']];
        }

        foreach ($this->pack()['leakage'] as $r) {
            if ($r['dn'] === $dn) {
                return $isCheck
                    ? ['seat' => 'METAL', 'valve' => 'CHECK', 'basis' => 'TABLE',
                       'liquidMlPerMin' => (float) $r['checkLiquidMlPerMin'],
                       'gasM3PerH' => (float) $r['checkGasM3PerH'],
                       'gasFt3PerH' => (float) $r['checkGasFt3PerH']]
                    : ['seat' => 'METAL', 'valve' => 'OTHER', 'basis' => 'TABLE',
                       'dropsPerMin' => (float) $r['metalLiquidDropsPerMin'],
                       'liquidMlPerMin' => (float) $r['metalLiquidMlPerMin'],
                       'bubblesPerMin' => (float) $r['metalGasBubblesPerMin'],
                       'gasMlPerMin' => (float) $r['metalGasMlPerMin']];
            }
        }

        $f = $this->pack()['leakageFormulasAboveTable'];
        if ($dn <= (int) $f['appliesAboveDn']) {
            return ['seat' => 'METAL', 'basis' => 'NOT_TABULATED'];
        }

        // Formula territory. NPS, not DN, drives them.
        $nps = $this->npsForDn($dn);
        if ($nps === null) {
            return ['seat' => 'METAL', 'basis' => 'NOT_TABULATED'];
        }

        return $isCheck
            ? ['seat' => 'METAL', 'valve' => 'CHECK', 'basis' => 'FORMULA',
               'liquidMlPerMin' => 3 * $nps, 'gasM3PerH' => 0.042 * $nps,
               'gasFt3PerH' => 1.5 * $nps]
            : ['seat' => 'METAL', 'valve' => 'OTHER', 'basis' => 'FORMULA',
               'dropsPerMin' => 2 * $nps, 'bubblesPerMin' => 4 * $nps];
    }

    /** @return array<string,string> */
    public function notes(): array
    {
        return $this->pack()['requirementNotes'] ?? [];
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    // ---------------------------------------------------------- internals --

    private function columnFor(string $valveType, ?string $variant): ?string
    {
        $type = strtoupper(trim($valveType));
        $var = $variant === null ? null : strtoupper(trim($variant));

        if ($var === null && $this->requiresVariant($type)) {
            return null;    // ambiguous on purpose; refusalFor() explains
        }

        foreach ($this->pack()['columns'] as $name => $col) {
            foreach (array_merge([$col], $col['alsoCovers'] ?? []) as $c) {
                if ($c['valveType'] === $type && ($c['variant'] ?? null) === $var) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $band */
    private function inSizeBand(int $dn, array $band): bool
    {
        if ($band['maxDn'] !== null && $dn > $band['maxDn']) {
            return false;
        }

        return ! ($band['minDnExclusive'] !== null && $dn <= $band['minDnExclusive']);
    }

    /** @param array<string,mixed> $band */
    private function inClassBand(int $class, array $band): bool
    {
        if ($band['maxClass'] !== null && $class > $band['maxClass']) {
            return false;
        }

        return ! ($band['minClassExclusive'] !== null && $class <= $band['minClassExclusive']);
    }

    /** @return array<string,mixed> */
    private function durationBand(int $dn): array
    {
        foreach ($this->pack()['duration'] as $b) {
            $okLow = $b['dnFrom'] === null || $dn >= $b['dnFrom'];
            $okHigh = $b['dnTo'] === null || $dn <= $b['dnTo'];
            if ($okLow && $okHigh) {
                return $b;
            }
        }

        // The bands tile the whole range and the validator proves it, so this
        // is unreachable unless the pack has been edited without re-validating.
        throw new RuntimeException("No API 598 duration band covers DN {$dn}");
    }

    private function pressureRule(string $test, string $valveType): ?string
    {
        if ($test === self::SHELL) {
            return $this->pack()['shellTestRule']['code'];
        }
        foreach ($this->pack()['closureTestPressure'] as $c) {
            if ($c['test'] !== $test) {
                continue;
            }
            if (! $this->closureRowApplies($c, $valveType)) {
                continue;
            }

            return $c['rule'] ?? null;
        }

        return null;
    }

    private function pressureBarG(
        string $test,
        string $valveType,
        string $pressureClass,
        ?string $materialGroup,
    ): ?float {
        if ($test === self::SHELL) {
            return $this->shellTestPressureBarG($pressureClass, $materialGroup);
        }

        foreach ($this->pack()['closureTestPressure'] as $c) {
            if ($c['test'] !== $test || ! $this->closureRowApplies($c, $valveType)) {
                continue;
            }
            if (isset($c['barG'])) {
                return (float) $c['barG'];
            }
            if (($c['rule'] ?? null) === 'ONE_HUNDRED_AND_TEN_PERCENT_OF_RATING_AT_38C'
                && $materialGroup !== null) {
                $kpa = $this->ratings->ratingAt($materialGroup, $pressureClass, 38.0);

                return $kpa === null ? null : round($kpa / 100 * 1.1, 1);
            }

            // 110 % of the DESIGN DIFFERENTIAL, which is a purchase-order
            // figure and not a rating. No standard in this repo holds it.
            return null;
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private function closureRowApplies(array $row, string $valveType): bool
    {
        $type = strtoupper(trim($valveType));

        return match ($row['appliesTo']) {
            'BUTTERFLY' => $type === 'BUTTERFLY',
            'CHECK' => $type === 'CHECK' && ($row['material'] ?? null) === 'STEEL_AND_ALLOYS',
            default => ! in_array($type, ['BUTTERFLY', 'CHECK'], true),
        };
    }

    private function npsForDn(int $dn): ?float
    {
        foreach ($this->pack()['leakage'] as $r) {
            if ($r['dn'] === $dn) {
                return (float) $r['nps'];
            }
        }

        // Above the table there is no DN/NPS pair here; 25 mm to the inch is
        // the nominal relation and the formulas are stated in NPS.
        return $dn > 1200 ? round($dn / 25.0) : null;
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
