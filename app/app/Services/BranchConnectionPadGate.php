<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Piping\Exceptions\PadGateRefusal;
use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\MaterialReference;
use App\Support\Reference\PipeReference;
use InvalidArgumentException;

/**
 * ADR-008 Step B (requirement P-24): the ASME B31.3 para 304.3.3 check of
 * every branch-table cell at publish time.
 *
 * For one branch rule the gate walks the WORST CASE of its footprint -
 * every (header DN, branch DN) pair from the platform's DN vocabulary that
 * falls inside header_range x branch_range - sizing each pipe with the
 * schedule and material its own DN band rules assign, and runs the
 * area-replacement check (`BranchReinforcementCalculator`):
 *
 *   - a PAD rule must PASS with its recorded pad thickness (zone-filling
 *     pad OD, minimum-weld credit per para 328.5.4, mill tolerance 0.875
 *     per the owner decision 2026-10-12);
 *   - a NONE rule must PASS bare, otherwise the rule must say PAD.
 *
 * Conventions (documented because they are decisions, not code accidents):
 *   - wall: nominal wall x 0.875, then the corrosion allowance c is
 *     subtracted where the code subtracts it (Appendix H convention);
 *   - c for the pair is the WORST of the two bands' corrosion allowances;
 *   - E comes from Table A-3 via the material's `pipe_type` when the rule
 *     carries one; otherwise the LOWEST Ej published for the spec is used —
 *     a smaller E thickens the required wall, so it is the conservative
 *     reading;
 *   - W: 1.0 below 427 deg C for every group (the table starts there);
 *     above, by material family (filled austenitic / CrMo / carbon steel);
 *   - Y from Table 304.1.1-1, ferritic or austenitic by family;
 *   - pairs outside para 304.3.1(b) applicability (Dh/Th >= 100 with
 *     Db >= Dh/2) are REFUSED, never silently skipped;
 *   - pad material is assumed as strong as the run (sPadOverRun = 1); a
 *     weaker pad material is a manual-override concern at the rule level.
 */
final class BranchConnectionPadGate
{
    public function __construct(
        private readonly PipeReference $sizes,
        private readonly MaterialReference $materials,
    ) {
    }

    /**
     * @param  list<int>             $headerDns    DNs of the pack inside header_range
     * @param  list<int>             $branchDns    DNs of the pack inside branch_range
     * @param  callable(int):string  $scheduleFor  DN -> schedule label of that band
     * @param  callable(int):float   $caFor        DN -> corrosion allowance, mm
     * @param  callable(int):array{spec:string,grade:string,psl:?string,pipe_type:?string,s_mpa_override:?float,ej_override:?float,p_no:?string,group_no:?string} $materialFor
     * @return array{pass:bool, violations:list<string>, worst:?array<string,mixed>}
     */
    public function checkCell(
        float $pressureMpa,
        float $temperatureC,
        array $headerDns,
        array $branchDns,
        callable $scheduleFor,
        callable $caFor,
        callable $materialFor,
        string $reinforcement,
        ?float $padThickness,
    ): array {
        $violations = [];
        $worst = null;

        foreach ($headerDns as $dh) {
            foreach ($branchDns as $db) {
                if ($db > $dh) {
                    continue; // a branch never exceeds its header
                }

                try {
                    $case = $this->pairCase(
                        $pressureMpa, $temperatureC, $dh, $db,
                        $scheduleFor, $caFor, $materialFor,
                    );
                } catch (PadGateRefusal $e) {
                    $violations[] = sprintf('DN%d x DN%d: %s', $dh, $db, $e->getMessage());

                    continue;
                }

                $pad = $reinforcement === 'PAD' ? $padThickness : null;
                $result = BranchReinforcementCalculator::evaluate(
                    Dh: $case['dh'], Th: $case['th_wall'], th: $case['th'],
                    Db: $case['db'], Tb: $case['tb_wall'], tb: $case['tb'],
                    c: $case['c'], padThickness: $pad, creditWelds: true,
                );

                if (! $result['pass']) {
                    $required = BranchReinforcementCalculator::requiredPadThickness(
                        Dh: $case['dh'], Th: $case['th_wall'], th: $case['th'],
                        Db: $case['db'], Tb: $case['tb_wall'], tb: $case['tb'],
                        c: $case['c'],
                    );

                    $violations[] = $reinforcement === 'PAD'
                        ? sprintf(
                            'DN%d x DN%d: recorded pad %.3f mm is insufficient; para 304.3.3 requires at least %.3f mm.',
                            $dh, $db, (float) $padThickness, $required
                        )
                        : sprintf(
                            'DN%d x DN%d: the bare connection fails para 304.3.3; a pad of at least %.3f mm is required.',
                            $dh, $db, $required
                        );
                }

                if ($worst === null || $result['margin'] < $worst['margin']) {
                    $worst = [
                        'header_dn' => $dh,
                        'branch_dn' => $db,
                        'pass' => $result['pass'],
                        'margin' => $result['margin'],
                        'a1' => $result['A1'],
                        'available' => $result['available'],
                    ];
                }
            }
        }

        return ['pass' => $violations === [], 'violations' => $violations, 'worst' => $worst];
    }

    /**
     * Everything the area-replacement check needs for one DN pair.
     *
     * @param  callable(int):string  $scheduleFor
     * @param  callable(int):float   $caFor
     * @param  callable(int):array<string,mixed> $materialFor
     * @return array{dh:float,db:float,th_wall:float,tb_wall:float,th:float,tb:float,c:float}
     *
     * @throws PadGateRefusal
     */
    private function pairCase(
        float $pressureMpa,
        float $temperatureC,
        int $dhDn,
        int $dbDn,
        callable $scheduleFor,
        callable $caFor,
        callable $materialFor,
    ): array {
        $mill = $this->materials->millToleranceFactor();

        [$dhOd, $thNominal] = $this->odAndWall($dhDn, $scheduleFor);
        [$dbOd, $tbNominal] = $this->odAndWall($dbDn, $scheduleFor);

        $thWall = $thNominal * $mill;
        $tbWall = $tbNominal * $mill;

        // Worst allowance of the two bands governs the joint.
        $c = max($caFor($dhDn), $caFor($dbDn));

        if ($thWall - $c <= 0 || $tbWall - $c <= 0) {
            throw new PadGateRefusal(
                'corrosion allowance consumes the effective wall'
            );
        }

        // Para 304.3.1(b): outside these limits the simple equations do not
        // apply, and a gate must refuse what it cannot check.
        if ($dhOd / $thWall >= 100.0 && $dbOd >= $dhOd / 2.0) {
            throw new PadGateRefusal(
                'outside para 304.3.1(b) applicability (Dh/Th >= 100 requires Db < Dh/2)'
            );
        }

        [$sh, $eh] = $this->stressAndQuality($materialFor($dhDn), $temperatureC);
        [$sb, $eb] = $this->stressAndQuality($materialFor($dbDn), $temperatureC);

        $w = $this->wFactor($materialFor($dhDn)['spec'], $materialFor($dbDn)['spec'], $temperatureC);
        $yRun = $this->materials->yCoefficient($this->family($materialFor($dhDn)['spec']), $temperatureC);
        $yBranch = $this->materials->yCoefficient($this->family($materialFor($dbDn)['spec']), $temperatureC);

        try {
            $th = BranchReinforcementCalculator::pressureDesignThickness($pressureMpa, $dhOd, $sh, $eh, $w, $yRun);
            $tb = BranchReinforcementCalculator::pressureDesignThickness($pressureMpa, $dbOd, $sb, $eb, $w, $yBranch);
        } catch (InvalidArgumentException $e) {
            throw new PadGateRefusal($e->getMessage());
        }

        return [
            'dh' => $dhOd, 'db' => $dbOd,
            'th_wall' => $thWall, 'tb_wall' => $tbWall,
            'th' => $th, 'tb' => $tb,
            'c' => $c,
        ];
    }

    /** @param callable(int):string $scheduleFor */
    private function odAndWall(int $dn, callable $scheduleFor): array
    {
        try {
            $size = $this->sizes->byDn($dn);
        } catch (\App\Support\Reference\Exceptions\UnknownSizeException) {
            throw new PadGateRefusal("DN{$dn} is not in the pipe-size reference pack");
        }

        $schedule = (string) $scheduleFor($dn);

        try {
            $wall = $this->sizes->wall($size->nps, $schedule);
        } catch (\App\Support\Reference\Exceptions\UnknownScheduleException) {
            throw new PadGateRefusal("schedule {$schedule} is not published for DN{$dn}");
        }

        return [$size->odMm, $wall];
    }

    /**
     * S at the design temperature plus the longitudinal quality factor E.
     *
     * @param  array<string,mixed>  $material
     * @return array{0:float,1:float} [S MPa, E]
     *
     * @throws PadGateRefusal
     */
    private function stressAndQuality(array $material, float $temperatureC): array
    {
        $spec = (string) $material['spec'];
        $grade = (string) $material['grade'];
        $psl = isset($material['psl']) && $material['psl'] !== null ? (string) $material['psl'] : null;

        $override = $material['s_mpa_override'] ?? null;
        if ($override !== null) {
            $s = (float) $override;
        } else {
            try {
                $s = $this->materials->allowableStress($spec, $grade, $temperatureC, $psl);
            } catch (UnknownMaterialException $e) {
                throw new PadGateRefusal($e->getMessage());
            }
        }

        $ejOverride = $material['ej_override'] ?? null;
        if ($ejOverride !== null) {
            return [$s, (float) $ejOverride];
        }

        $pipeType = $material['pipe_type'] ?? null;
        if ($pipeType !== null) {
            try {
                return [$s, $this->materials->ej($spec, (string) $pipeType)];
            } catch (UnknownMaterialException $e) {
                throw new PadGateRefusal($e->getMessage());
            }
        }

        return [$s, $this->lowestEj($spec)];
    }

    /**
     * Conservative default when the manufacturing type is not recorded:
     * the lowest Ej published for the spec (a smaller E thickens the
     * required wall). Seamless-only specs naturally resolve to 1.0.
     *
     * @throws PadGateRefusal
     */
    private function lowestEj(string $spec): float
    {
        static $cache = [];
        if (isset($cache[$spec])) {
            return $cache[$spec];
        }

        $lowest = null;
        foreach (['S', 'E', 'F', null] as $type) {
            foreach (['seamless', 'electric welded', 'furnace', 'double butt', 'single butt', '100% radiographed', 'plate'] as $hint) {
                try {
                    $ej = $this->materials->ej($spec, $type === null ? null : (string) $type, $hint);
                } catch (UnknownMaterialException) {
                    continue;
                }
                $lowest = $lowest === null ? $ej : min($lowest, $ej);
            }
        }

        if ($lowest === null) {
            throw new PadGateRefusal("no Table A-3 quality factor published for {$spec}");
        }

        return $cache[$spec] = $lowest;
    }

    private function family(string $spec): string
    {
        return match ($this->norm($spec)) {
            'A312', 'A358', 'A376', 'A409', 'A790' => 'austenitic',
            default => 'ferritic',
        };
    }

    /**
     * Table 302.3.5-1: W is 1.0 below the tabulated range of every group
     * (General Note (a)), and the lowest tabulated column is 427 deg C -
     * so the simple 1.0 covers every pad-gate design temperature up to
     * there. Above it, the joined pair is only as strong as its weaker
     * family: austenitics take the filled-austenitic row, CrMo pipes the
     * CrMo row, everything else the carbon-steel row (which ends at
     * 593 deg C - beyond that the gate refuses).
     *
     * @throws PadGateRefusal
     */
    private function wFactor(string $runSpec, string $branchSpec, float $temperatureC): float
    {
        if ($temperatureC <= 427.0) {
            return 1.0;
        }

        // The joint is only as strong as its weaker side: take the MINIMUM
        // W of the two materials' groups (any dash/out-of-range refuses).
        $w = null;
        foreach ([$runSpec, $branchSpec] as $spec) {
            $group = match ($this->norm($spec)) {
                'A312', 'A358', 'A376', 'A409', 'A790' => 'filled_austenitic',
                'A335' => 'cr_mo',
                default => 'carbon_steel',
            };

            try {
                $candidate = $this->materials->wFactor($group, $temperatureC);
            } catch (UnknownMaterialException $e) {
                throw new PadGateRefusal($e->getMessage());
            }

            $w = $w === null ? $candidate : min($w, $candidate);
        }

        return $w ?? 1.0;
    }

    private function norm(string $spec): string
    {
        return strtoupper(str_replace([' ', '-', '_'], '', $spec));
    }
}
