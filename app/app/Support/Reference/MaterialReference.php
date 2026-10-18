<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Support\Reference\Exceptions\UnknownMaterialException;
use RuntimeException;

/**
 * Read-only access to the ASME B31.3-2024 materials reference pack
 * (standards-packs/reference/b313-materials.ref.json).
 *
 * WHY THIS EXISTS AS A FILE, NOT A TABLE
 * --------------------------------------
 * Same reasoning as PipeReference (ADR-007): Table A-1 values are facts of
 * the code edition, not tenant data. ADR-008 s9.3 decision 2026-10-12: class
 * rules may quote a pipe material grade, and the publish gate resolves S, Ej
 * and the 12.5 % mill convention from THIS pack; manual entry remains a
 * documented override at the rule level, never here.
 *
 * LOOKUP SEMANTICS
 * ----------------
 * - S interpolation: linear between tabulated columns (pack's stated
 *   semantics); below the first column the first value applies; above the
 *   last column, or above the material's maximum use temperature, every
 *   lookup THROWS - never extrapolates.
 * - W: 1.0 below the tabulated range of a group (Table 302.3.5-1 General
 *   Note (a)); a dash ("..." in the code) throws, it does not interpolate
 *   across it.
 * - Y: below 482 deg C the first column applies; the last column covers its
 *   own temperature and above.
 *
 * MEMOISATION: decoded once per process, indexed on first use.
 */
final class MaterialReference
{
    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    /** @var array<string, B313Material>|null keyed by "SPEC|GRADE|PSL" */
    private ?array $byKey = null;

    /** @var array<string, list<B313Material>>|null keyed by "SPEC|GRADE" */
    private ?array $bySpecGrade = null;

    public function __construct(private readonly string $refDir)
    {
    }

    // ------------------------------------------------------------- pack ---

    /** Code edition this pack was extracted from. */
    public function code(): string
    {
        return (string) $this->pack()['code'];
    }

    /**
     * Owner decision 2026-10-12: effective wall = 0.875 x nominal wall for
     * pipe bought to the usual 12.5 % mill tolerance - the convention every
     * Appendix H example uses.
     */
    public function millToleranceFactor(): float
    {
        return (float) $this->pack()['mill_tolerance_default'];
    }

    // -------------------------------------------------------- materials ---

    /**
     * Resolve one Table A-1 row. API 5L accepts either the ISO grade name as
     * published (L290) or the familiar X-grade (X42) via the pack's alias
     * table.
     *
     * @throws UnknownMaterialException
     */
    public function find(string $spec, string $grade, ?string $psl = null): B313Material
    {
        $this->indexMaterials();
        $spec = self::normaliseSpec($spec);
        $grade = strtoupper(trim($grade));

        foreach ($this->gradeCandidates($spec, $grade) as $candidate) {
            if ($psl !== null) {
                $hit = $this->byKey[$this->key($spec, $candidate, strtoupper(trim($psl)))] ?? null;
                if ($hit !== null) {
                    return $hit;
                }

                continue;
            }

            $matches = $this->bySpecGrade[$spec . '|' . $candidate] ?? [];
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                throw UnknownMaterialException::pslRequired(
                    $spec, $candidate,
                    array_values(array_unique(array_map(
                        static fn (B313Material $m): string => (string) $m->psl, $matches
                    )))
                );
            }
        }

        throw UnknownMaterialException::material($spec, $grade, $psl);
    }

    /**
     * Basic allowable stress S in MPa at the given metal temperature,
     * linearly interpolated between tabulated columns.
     *
     * @throws UnknownMaterialException
     */
    public function allowableStress(string $spec, string $grade, float $tempC, ?string $psl = null): float
    {
        return $this->stressOf($this->find($spec, $grade, $psl), $tempC);
    }

    /** @throws UnknownMaterialException */
    public function stressOf(B313Material $material, float $tempC): float
    {
        if ($tempC > $material->maxTempC) {
            throw UnknownMaterialException::temperatureAboveMax(
                $material->label(), $tempC, (float) $material->maxTempC
            );
        }

        $temps = $material->temps;
        $values = $material->sMpa;
        $last = count($temps) - 1;

        if ($tempC > $temps[$last]) {
            throw UnknownMaterialException::temperatureAboveTable(
                $material->label(), $tempC, (float) $temps[$last]
            );
        }

        if ($tempC <= $temps[0]) {
            return $this->demand($material, $values[0], $tempC);
        }

        for ($i = 0; $i < $last; $i++) {
            if ($tempC >= $temps[$i] && $tempC <= $temps[$i + 1]) {
                if ($tempC === (float) $temps[$i]) {
                    return $this->demand($material, $values[$i], $tempC);
                }
                if ($tempC === (float) $temps[$i + 1]) {
                    return $this->demand($material, $values[$i + 1], $tempC);
                }

                $lo = $values[$i];
                $hi = $values[$i + 1];
                if ($lo === null || $hi === null) {
                    throw UnknownMaterialException::noStressAt($material->label(), $tempC);
                }

                $fraction = ($tempC - $temps[$i]) / ($temps[$i + 1] - $temps[$i]);

                return $lo + $fraction * ($hi - $lo);
            }
        }

        throw UnknownMaterialException::noStressAt($material->label(), $tempC);
    }

    // ---------------------------------------------------------- Table A-3 ---

    /**
     * Table A-3 basic quality factor Ej for the longitudinal weld of a pipe.
     * `$type` narrows by class/type column (e.g. "S", "E", "F" for A53);
     * `$descriptionLike` narrows by a case-insensitive description fragment
     * (e.g. "seamless", "double butt", "100% radiographed").
     *
     * @throws UnknownMaterialException when nothing or more than one row matches
     */
    public function ej(string $spec, ?string $type = null, ?string $descriptionLike = null): float
    {
        $spec = self::normaliseSpec($spec);
        $rows = array_filter(
            (array) $this->pack()['table_a3_ej']['rows'],
            static fn (array $r): bool => self::normaliseSpec((string) $r['spec']) === $spec
        );

        if ($type !== null) {
            $want = strtoupper(trim($type));
            $rows = array_filter(
                $rows,
                static fn (array $r): bool => $r['type'] !== null && strtoupper((string) $r['type']) === $want
            );
        }

        if ($descriptionLike !== null) {
            $needle = mb_strtolower($descriptionLike);
            $rows = array_filter(
                $rows,
                static fn (array $r): bool => str_contains(mb_strtolower((string) $r['description']), $needle)
            );
        }

        $rows = array_values($rows);
        if (count($rows) === 0) {
            throw UnknownMaterialException::ej($spec, $type ?? $descriptionLike);
        }
        if (count($rows) > 1) {
            throw UnknownMaterialException::ambiguousEj($spec, count($rows));
        }

        return (float) $rows[0]['ej'];
    }

    // ------------------------------------------- Table 304.1.1-1 (Y) ------

    /**
     * Coefficient Y for the pressure design thickness equation (t < D/6).
     * `$family`: one of ferritic | austenitic | gray_iron | other_ductile.
     * Interpolated for intermediate temperatures, as the table allows.
     *
     * @throws UnknownMaterialException
     */
    public function yCoefficient(string $family, float $tempC): float
    {
        $rows = (array) $this->pack()['table_304_1_1_y']['rows'];
        $row = null;
        foreach ($rows as $candidate) {
            if (str_starts_with((string) $candidate['family'], $family)) {
                $row = $candidate;

                break;
            }
        }
        if ($row === null) {
            throw UnknownMaterialException::factorRow('Table 304.1.1-1', $family);
        }

        return $this->interpolateRow(
            'Table 304.1.1-1', (string) $row['family'],
            array_map('floatval', (array) $this->pack()['table_304_1_1_y']['temps_c']),
            array_map(static fn ($v) => $v === null ? null : (float) $v, (array) $row['y']),
            $tempC,
            belowFirst: 'first', aboveLast: 'last'
        );
    }

    // ------------------------------------------- Table 302.3.5-1 (W) ------

    /**
     * Weld joint strength reduction factor W. `$group`: one of
     * carbon_steel | cr_mo | csef_normalised_and_tempered |
     * csef_subcritical_PWHT | autogenous_austenitic... | filled_austenitic...
     * (prefix matching, like yCoefficient). Returns 1.0 below the tabulated
     * range of the group (General Note (a)); a dash throws.
     *
     * @throws UnknownMaterialException
     */
    public function wFactor(string $group, float $tempC): float
    {
        $rows = (array) $this->pack()['table_302_3_5_w']['rows'];
        $row = null;
        foreach ($rows as $candidate) {
            if (str_starts_with((string) $candidate['group'], $group)) {
                $row = $candidate;

                break;
            }
        }
        if ($row === null) {
            throw UnknownMaterialException::factorRow('Table 302.3.5-1', $group);
        }

        $temps = array_map('floatval', (array) $this->pack()['table_302_3_5_w']['temps_c']);
        $values = array_map(static fn ($v) => $v === null ? null : (float) $v, (array) $row['w']);

        $firstNonNull = null;
        foreach ($values as $i => $v) {
            if ($v !== null) {
                $firstNonNull = $i;

                break;
            }
        }

        if ($firstNonNull !== null && $tempC < $temps[$firstNonNull]) {
            return 1.0; // General Note (a)
        }

        return $this->interpolateRow(
            'Table 302.3.5-1', (string) $row['group'], $temps, $values, $tempC,
            belowFirst: 'throw', aboveLast: 'throw'
        );
    }

    // ----------------------------------------------------------- plumbing ---

    /**
     * @param list<float>      $temps
     * @param list<float|null> $values
     *
     * @throws UnknownMaterialException
     */
    private function interpolateRow(
        string $table, string $key, array $temps, array $values, float $tempC,
        string $belowFirst, string $aboveLast
    ): float {
        $last = count($temps) - 1;

        if ($tempC <= $temps[0]) {
            if ($belowFirst === 'throw') {
                throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
            }

            return $values[0] ?? throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
        }

        if ($tempC > $temps[$last]) {
            if ($aboveLast === 'last') {
                return $values[$last] ?? throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
            }

            throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
        }

        // A published value at an exact column is valid even when the
        // preceding column is a dash; interpolation across the dash is not.
        foreach ($temps as $i => $columnTemp) {
            if ($tempC === $columnTemp) {
                return $values[$i] ?? throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
            }
        }

        for ($i = 0; $i < $last; $i++) {
            if ($tempC >= $temps[$i] && $tempC <= $temps[$i + 1]) {
                $lo = $values[$i];
                $hi = $values[$i + 1];
                if ($lo === null || $hi === null) {
                    throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
                }
                if ($tempC === $temps[$i]) {
                    return $lo;
                }
                if ($tempC === $temps[$i + 1]) {
                    return $hi;
                }

                $fraction = ($tempC - $temps[$i]) / ($temps[$i + 1] - $temps[$i]);

                return $lo + $fraction * ($hi - $lo);
            }
        }

        throw UnknownMaterialException::factorNotApplicable($table, $key, $tempC);
    }

    /** @throws UnknownMaterialException */
    private function demand(B313Material $material, ?float $value, float $tempC): float
    {
        return $value ?? throw UnknownMaterialException::noStressAt($material->label(), $tempC);
    }

    /** @return list<string> most specific first */
    private function gradeCandidates(string $spec, string $grade): array
    {
        $candidates = [$grade];
        if ($spec === 'API5L' && isset(self::aliasMap()[$grade])) {
            $candidates[] = self::aliasMap()[$grade];
        }

        return $candidates;
    }

    private function key(string $spec, string $grade, ?string $psl): string
    {
        return implode('|', [$spec, $grade, strtoupper((string) $psl)]);
    }

    private function indexMaterials(): void
    {
        if ($this->byKey !== null) {
            return;
        }

        $index = [];
        $this->bySpecGrade = [];
        foreach ((array) $this->pack()['table_a1_si'] as $row) {
            $material = new B313Material(
                spec: (string) $row['spec'],
                typeGrade: (string) $row['type_grade'],
                psl: isset($row['psl']) && $row['psl'] !== null ? (string) $row['psl'] : null,
                descriptor: (string) ($row['descriptor'] ?? ''),
                uns: isset($row['uns']) && $row['uns'] !== null ? (string) $row['uns'] : null,
                pNo: isset($row['p_no']) && $row['p_no'] !== null ? (string) $row['p_no'] : null,
                groupNo: isset($row['group_no']) && $row['group_no'] !== null ? (string) $row['group_no'] : null,
                minTempC: (string) ($row['min_temp_c'] ?? ''),
                tensileMpa: (int) $row['tensile_mpa'],
                yieldMpa: (int) $row['yield_mpa'],
                maxTempC: (int) $row['max_temp_c'],
                temps: array_map('intval', (array) $row['temps']),
                sMpa: array_map(static fn ($v) => $v === null ? null : (float) $v, (array) $row['s_mpa']),
            );
            $index[$this->key($material->spec, $material->typeGrade, $material->psl)] = $material;
            $this->bySpecGrade[$material->spec . '|' . $material->typeGrade][] = $material;
        }

        $this->byKey = $index;
    }

    /** X-grade -> ISO L-grade, from the pack (Table A-1 lists L names). */
    private function aliasMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ((array) ($this->pack()['api5l_iso_grade_aliases'] ?? []) as $iso => $alias) {
                $map[strtoupper((string) $alias)] = (string) $iso;
            }
        }

        return $map;
    }

    /** "API 5L", "api-5l", "A-53" -> "API5L", "A53". */
    private static function normaliseSpec(string $spec): string
    {
        return strtoupper(str_replace([' ', '-', '_'], '', trim($spec)));
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/') . '/b313-materials.ref.json';
        if (! is_file($path)) {
            throw new RuntimeException("Materials reference pack not found at {$path}");
        }

        return $this->pack = json_decode(
            (string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR
        );
    }
}
