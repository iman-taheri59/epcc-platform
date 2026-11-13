<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Piping\Exceptions\PadGateRefusal;
use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\Exceptions\UnknownScheduleException;
use App\Support\Reference\Exceptions\UnknownSizeException;
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
 *   - a PAD rule must PASS with its nominal pipe-saddle thickness reduced
 *     by the Appendix-H 0.875 factor for L4/A4 metal area. The A4 width is
 *     capped by the physical two-axis geometry's span along the header/run
 *     (the Figure 304.3.3-1 D_s direction); the A4 credited height is
 *     min(Tr_min, L4), while note (2)'s physical width/height ratio uses the
 *     full Tr_min. Root and PAD outer-edge weld credits follow Appendix H
 *     H301/H302. PAD-to-branch minimum throat is separately recorded at
 *     installation but not used for extra A4 credit.
 *   - a NONE rule must PASS bare, otherwise the rule must say PAD.
 *   - every DN pair is checked over β=45..90 deg at the exact piecewise
 *     transition candidates plus both endpoints; the minimum margin and its
 *     controlling β are retained. A physical saddle can still be installed
 *     only at a centred 90-degree root; the class gate does not approve an
 *     angled PAD profile.
 *
 * Conventions (documented because they are decisions, not code accidents):
 *   - area-equation pipe walls: nominal schedule wall x 0.875, then the
 *     corrosion allowance c is subtracted where specified; each pipe is
 *     refused if this minimum wall is below t+c;
 *   - current PAD form: the rule thickness is nominal pipe-saddle wall;
 *     0.875 is applied to its L4/A4 metal thickness, while §328.5.4 weld
 *     minima use nominal member thicknesses. Plate PADs are not distinguished
 *     by the present rule schema;
 *   - root weld throat uses nominal branch wall under §328.5.4(c); PAD edge
 *     throat follows §328.5.4(f) and Appendix H H302;
 *   - A3 is reduced by min(1, S_branch/S_run) per para 304.3.3(c)(2);
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
 *   - if `value.pad_material` is present on the branch rule, its Table A-1
 *     allowable stress is used for A4 (capped at run S); otherwise PAD material
 *     defaults to the header DN's `PIPE_MATERIAL` rule, so S_pad/S_run=1.
 */
final class BranchConnectionPadGate
{
    public function __construct(
        private readonly PipeReference $sizes,
        private readonly MaterialReference $materials,
    ) {}

    /**
     * @param  list<int>  $headerDns  DNs of the pack inside header_range
     * @param  list<int>  $branchDns  DNs of the pack inside branch_range
     * @param  callable(int):string  $scheduleFor  DN -> schedule label of that band
     * @param  callable(int):float  $caFor  DN -> corrosion allowance, mm
     * @param  callable(int):array{spec:string,grade:string,psl:?string,pipe_type:?string,s_mpa_override:?float,ej_override:?float,p_no:?string,group_no:?string}  $materialFor
     * @param  array<string,mixed>|null  $padMaterial  Optional branch-rule `value.pad_material`; null defaults to the header material for each DN pair.
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
        ?array $padMaterial = null,
    ): array {
        $violations = [];
        $worst = null;

        if (! in_array($reinforcement, ['NONE', 'PAD'], true)) {
            return [
                'pass' => false,
                'violations' => ['Reinforcement must be NONE or PAD.'],
                'worst' => null,
            ];
        }

        if ($reinforcement === 'PAD'
            && ($padThickness === null || ! is_finite($padThickness) || $padThickness <= 0.0)) {
            return [
                'pass' => false,
                'violations' => ['A PAD rule requires a finite positive nominal saddle thickness.'],
                'worst' => null,
            ];
        }

        if ($reinforcement === 'NONE' && $padThickness !== null) {
            return [
                'pass' => false,
                'violations' => ['A NONE rule cannot carry a PAD thickness.'],
                'worst' => null,
            ];
        }

        if ($reinforcement === 'NONE' && $padMaterial !== null) {
            return [
                'pass' => false,
                'violations' => ['A NONE rule cannot carry a PAD material.'],
                'worst' => null,
            ];
        }

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
                $padSpanAlongRun = null;
                $padEffectiveThicknessFactor = $this->materials->millToleranceFactor();
                $padGeometryError = null;
                $sPadOverRun = 1.0;
                $padMaterialSource = null;

                if ($pad !== null) {
                    $selectedPadMaterial = $padMaterial ?? $case['header_material'];
                    try {
                        $sPad = $this->allowableStress($selectedPadMaterial, $temperatureC);
                    } catch (PadGateRefusal $e) {
                        $violations[] = sprintf('DN%d x DN%d: PAD material: %s', $dh, $db, $e->getMessage());

                        continue;
                    }
                    $sPadOverRun = min(1.0, $sPad / $case['s_run_mpa']);
                    $padMaterialSource = $padMaterial === null
                        ? 'HEADER_PIPE_MATERIAL'
                        : 'BRANCH_RULE_PAD_MATERIAL';
                }

                try {
                    $padGeometry = BranchPadGeometryCalculator::forCenteredRightAngleSaddle(
                        Dh: $case['dh'], Th: $case['th_wall'],
                        Db: $case['db'], Tb: $case['tb_wall'], c: $case['c'],
                    );
                    $recommendationPadSpanAlongRun = $padGeometry['pad_minor_axis_mm'];
                } catch (InvalidArgumentException $e) {
                    $recommendationPadSpanAlongRun = null;
                    $padGeometryError = $e;
                }

                if ($pad !== null) {
                    if ($padGeometryError !== null) {
                        $violations[] = sprintf('DN%d x DN%d: PAD saddle geometry is not supported: %s', $dh, $db, $padGeometryError->getMessage());

                        continue;
                    }
                    $padSpanAlongRun = $recommendationPadSpanAlongRun;
                }

                try {
                    $betaCandidates = BranchReinforcementCalculator::criticalBetaAngles(
                        Dh: $case['dh'], Th: $case['th_wall'],
                        Db: $case['db'], Tb: $case['tb_wall'], c: $case['c'],
                        // NONE still needs the finite PAD span's transitions
                        // when solving its required-thickness recommendation.
                        padSpanAlongRun: $recommendationPadSpanAlongRun,
                    );
                } catch (InvalidArgumentException $e) {
                    $violations[] = sprintf('DN%d x DN%d: %s', $dh, $db, $e->getMessage());

                    continue;
                }

                $pairWorst = null;
                $pairHasRefusal = false;
                $requiredPad = 0.0;
                $requiredPadBeta = null;

                foreach ($betaCandidates as $betaDeg) {
                    try {
                        $result = BranchReinforcementCalculator::evaluate(
                            Dh: $case['dh'], Th: $case['th_wall'], th: $case['th'],
                            Db: $case['db'], Tb: $case['tb_wall'], tb: $case['tb'],
                            c: $case['c'], betaDeg: $betaDeg,
                            padThickness: $pad, padSpanAlongRun: $padSpanAlongRun,
                            creditWelds: true,
                            sBranchOverRun: $case['s_branch_over_run'],
                            sPadOverRun: $sPadOverRun,
                            branchNominalThicknessForWeld: $case['tb_nominal_for_weld'],
                            padEffectiveThicknessFactor: $padEffectiveThicknessFactor,
                        );
                    } catch (InvalidArgumentException $e) {
                        $violations[] = sprintf('DN%d x DN%d: %s', $dh, $db, $e->getMessage());
                        $pairHasRefusal = true;

                        break;
                    }

                    $padWidthToHeightRatio = $result['pad_width_to_height_ratio'] ?? null;
                    if ($pad !== null && ($padWidthToHeightRatio === null
                        || ! is_finite((float) $padWidthToHeightRatio)
                        || (float) $padWidthToHeightRatio < 1.0 - 1e-9)) {
                        $violations[] = sprintf(
                            'DN%d x DN%d: PAD A4 width-to-height ratio %.3f:1 is below the 1:1 minimum in ASME B31.3-2024 Figure 304.3.3-1 note (2) at beta %.2f deg.',
                            $dh, $db, $padWidthToHeightRatio === null ? 0.0 : (float) $padWidthToHeightRatio, $betaDeg,
                        );
                        $pairHasRefusal = true;

                        break;
                    }

                    if (! $result['pass']) {
                        if ($recommendationPadSpanAlongRun === null) {
                            $message = $padGeometryError?->getMessage() ?? 'The two-axis saddle span could not be resolved.';
                            $violations[] = sprintf('DN%d x DN%d: cannot calculate a physical PAD thickness recommendation: %s', $dh, $db, $message);
                            $pairHasRefusal = true;

                            break;
                        }

                        $requiredAtBeta = BranchReinforcementCalculator::requiredPadThickness(
                            Dh: $case['dh'], Th: $case['th_wall'], th: $case['th'],
                            Db: $case['db'], Tb: $case['tb_wall'], tb: $case['tb'],
                            c: $case['c'], betaDeg: $betaDeg,
                            padSpanAlongRun: $recommendationPadSpanAlongRun,
                            sBranchOverRun: $case['s_branch_over_run'],
                            sPadOverRun: $sPadOverRun,
                            branchNominalThicknessForWeld: $case['tb_nominal_for_weld'],
                            padEffectiveThicknessFactor: $padEffectiveThicknessFactor,
                        );

                        if ($requiredAtBeta > 0.0) {
                            $requiredCheck = BranchReinforcementCalculator::evaluate(
                                Dh: $case['dh'], Th: $case['th_wall'], th: $case['th'],
                                Db: $case['db'], Tb: $case['tb_wall'], tb: $case['tb'],
                                c: $case['c'], betaDeg: $betaDeg,
                                padThickness: $requiredAtBeta, padSpanAlongRun: $recommendationPadSpanAlongRun,
                                creditWelds: true,
                                sBranchOverRun: $case['s_branch_over_run'],
                                sPadOverRun: $sPadOverRun,
                                branchNominalThicknessForWeld: $case['tb_nominal_for_weld'],
                                padEffectiveThicknessFactor: $padEffectiveThicknessFactor,
                            );
                            $requiredRatio = $requiredCheck['pad_width_to_height_ratio'] ?? null;
                            if ($requiredRatio === null || ! is_finite((float) $requiredRatio)
                                || (float) $requiredRatio < 1.0 - 1e-9) {
                                $violations[] = sprintf(
                                    'DN%d x DN%d: the %.3f mm nominal PAD needed at beta %.2f deg would violate the 1:1 minimum width-to-height ratio in ASME B31.3-2024 Figure 304.3.3-1 note (2).',
                                    $dh, $db, $requiredAtBeta, $betaDeg,
                                );
                                $pairHasRefusal = true;

                                break;
                            }

                            if (! $requiredCheck['pass']) {
                                $violations[] = sprintf(
                                    'DN%d x DN%d: no passing PAD thickness was established by the bounded area-balance solver at beta %.2f deg; engineering review is required.',
                                    $dh, $db, $betaDeg,
                                );
                                $pairHasRefusal = true;

                                break;
                            }
                        }

                        if ($requiredAtBeta > $requiredPad) {
                            $requiredPad = $requiredAtBeta;
                            $requiredPadBeta = $betaDeg;
                        }
                    }

                    if ($pairWorst === null || $result['margin'] < $pairWorst['margin']) {
                        $pairWorst = [
                            'header_dn' => $dh,
                            'branch_dn' => $db,
                            'beta_deg' => $betaDeg,
                            'pass' => $result['pass'],
                            'margin' => $result['margin'],
                            'a1' => $result['A1'],
                            'available' => $result['available'],
                            'pad_material_source' => $padMaterialSource,
                            's_pad_over_run' => $pad === null ? null : $sPadOverRun,
                        ];
                    }
                }

                if ($pairHasRefusal || $pairWorst === null) {
                    continue;
                }

                if ($requiredPadBeta !== null) {
                    $violations[] = $reinforcement === 'PAD'
                        ? sprintf(
                            'DN%d x DN%d: recorded nominal pipe-saddle thickness %.3f mm is insufficient; para 304.3.3 requires at least %.3f mm nominal over beta 45..90 deg (controlling beta %.2f deg, PAD metal reduced by 0.875).',
                            $dh, $db, (float) $padThickness, $requiredPad, $requiredPadBeta
                        )
                        : sprintf(
                            'DN%d x DN%d: the bare connection fails para 304.3.3; a pad of at least %.3f mm is required over beta 45..90 deg (controlling beta %.2f deg).',
                            $dh, $db, $requiredPad, $requiredPadBeta
                        );
                }

                if ($worst === null || $pairWorst['margin'] < $worst['margin']) {
                    $worst = $pairWorst;
                }
            }
        }

        return ['pass' => $violations === [], 'violations' => $violations, 'worst' => $worst];
    }

    /**
     * Everything the area-replacement check needs for one DN pair.
     *
     * @param  callable(int):string  $scheduleFor
     * @param  callable(int):float  $caFor
     * @param  callable(int):array<string,mixed>  $materialFor
     * @return array{
     *   dh:float,db:float,tb_nominal_for_weld:float,
     *   th_wall:float,tb_wall:float,th:float,tb:float,c:float,
     *   s_run_mpa:float,header_material:array<string,mixed>,s_branch_over_run:float
     * }
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

        $headerMaterial = $materialFor($dhDn);
        $branchMaterial = $materialFor($dbDn);
        [$sh, $eh] = $this->stressAndQuality($headerMaterial, $temperatureC);
        [$sb, $eb] = $this->stressAndQuality($branchMaterial, $temperatureC);

        $w = $this->wFactor((string) $headerMaterial['spec'], (string) $branchMaterial['spec'], $temperatureC);
        $yRun = $this->materials->yCoefficient($this->family((string) $headerMaterial['spec']), $temperatureC);
        $yBranch = $this->materials->yCoefficient($this->family((string) $branchMaterial['spec']), $temperatureC);

        try {
            $th = BranchReinforcementCalculator::pressureDesignThickness($pressureMpa, $dhOd, $sh, $eh, $w, $yRun);
            $tb = BranchReinforcementCalculator::pressureDesignThickness($pressureMpa, $dbOd, $sb, $eb, $w, $yBranch);
        } catch (InvalidArgumentException $e) {
            throw new PadGateRefusal($e->getMessage());
        }

        return [
            'dh' => $dhOd, 'db' => $dbOd,
            'tb_nominal_for_weld' => $tbNominal,
            'th_wall' => $thWall, 'tb_wall' => $tbWall,
            'th' => $th, 'tb' => $tb,
            'c' => $c,
            's_run_mpa' => $sh,
            'header_material' => $headerMaterial,
            // Para 304.3.3(c)(2) reduces A3 only when branch S is lower.
            's_branch_over_run' => min(1.0, $sb / $sh),
        ];
    }

    /** @param callable(int):string $scheduleFor */
    private function odAndWall(int $dn, callable $scheduleFor): array
    {
        try {
            $size = $this->sizes->byDn($dn);
        } catch (UnknownSizeException) {
            throw new PadGateRefusal("DN{$dn} is not in the pipe-size reference pack");
        }

        $schedule = (string) $scheduleFor($dn);

        try {
            $wall = $this->sizes->wall($size->nps, $schedule);
        } catch (UnknownScheduleException) {
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
        $spec = (string) ($material['spec'] ?? '');
        $s = $this->allowableStress($material, $temperatureC);

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
     * Resolve the B31.3 basic allowable stress S only. PAD material credit
     * does not use a pipe longitudinal quality factor E.
     *
     * @param  array<string,mixed>  $material
     *
     * @throws PadGateRefusal
     */
    private function allowableStress(array $material, float $temperatureC): float
    {
        $spec = $material['spec'] ?? null;
        $grade = $material['grade'] ?? null;
        $psl = $material['psl'] ?? null;
        if (! is_string($spec) || trim($spec) === ''
            || ! is_string($grade) || trim($grade) === ''
            || ($psl !== null && (! is_string($psl) || trim($psl) === ''))) {
            throw new PadGateRefusal('PAD material must identify a standard specification, grade and valid optional PSL');
        }

        $override = $material['s_mpa_override'] ?? null;
        if ($override !== null) {
            if (! is_numeric($override) || ! is_finite((float) $override) || (float) $override <= 0.0) {
                throw new PadGateRefusal('PAD material allowable-stress override must be finite and positive');
            }

            return (float) $override;
        }

        try {
            return $this->materials->allowableStress($spec, $grade, $temperatureC, $psl);
        } catch (UnknownMaterialException $e) {
            throw new PadGateRefusal($e->getMessage());
        }
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
