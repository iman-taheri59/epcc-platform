<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Minimum fillet-throat schedule for the physical PAD assembly.
 *
 * Inputs are nominal product thicknesses in millimetres. The pad is the
 * current pipe-saddle form: its nominal thickness is reduced separately by
 * the B31.3 Appendix-H mill-tolerance convention when it contributes metal
 * area; §328.5.4 weld minima use the nominal thicknesses.
 *
 * The pad-to-branch joint uses the fillet-weld option in §328.5.4(e)(2).
 * The pad-to-header edge follows §328.5.4(f). Minimums are throat dimensions;
 * the equal-leg sizes are only theoretical equivalents using the figure's
 * 0.707 throat-to-leg factor. They are not actual weld measurements.
 */
final class BranchPadWeldCalculator
{
    private const EQUAL_LEG_THROAT_FACTOR = 0.707;

    /**
     * @return array{
     *   root_branch_to_run:array{reference:string,minimum_throat_mm:float,equal_leg_equivalent_mm:float,a4_credit_mm2:float},
     *   pad_to_header:array{reference:string,minimum_throat_mm:float,equal_leg_equivalent_mm:float,a4_credit_mm2:float},
     *   pad_to_branch:array{reference:string,minimum_throat_mm:float,equal_leg_equivalent_mm:float,a4_credit_mm2:float,credited_in_area_balance:bool}
     * }
     */
    public static function minimums(
        float $branchNominalWallMm,
        float $padNominalThicknessMm,
        float $rootWeldThroatCapMm = 6.0,
    ): array {
        foreach (compact('branchNominalWallMm', 'padNominalThicknessMm', 'rootWeldThroatCapMm') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }

        // §328.5.4(c),(d): root cover-fillet throat tc = min(0.7 Tb, 6 mm).
        $rootThroat = min(0.7 * $branchNominalWallMm, $rootWeldThroatCapMm);

        // §328.5.4(f): the PAD outer edge is welded to the run with throat
        // >= 0.5 Tr. Appendix H H302 credits the two section cuts of this edge
        // weld as 2 * throat^2.
        $headerThroat = 0.5 * $padNominalThicknessMm;

        // §328.5.4(e)(2): for the selected fillet attachment, throat is at
        // least 0.7 t_min, where t_min is the lesser nominal branch/PAD wall.
        // This PAD-to-branch credit is deliberately not added to the A4
        // balance: Appendix H H302's reinforcement example credits the two
        // root welds and the PAD outer-edge weld, not this separate attachment.
        $branchThroat = 0.7 * min($branchNominalWallMm, $padNominalThicknessMm);

        $equalLeg = static fn (float $throat): float => $throat / self::EQUAL_LEG_THROAT_FACTOR;

        return [
            'root_branch_to_run' => [
                'reference' => 'ASME B31.3-2024 §328.5.4(c),(d)',
                'minimum_throat_mm' => $rootThroat,
                'equal_leg_equivalent_mm' => $equalLeg($rootThroat),
                'a4_credit_mm2' => 2.0 * $rootThroat ** 2,
            ],
            'pad_to_header' => [
                'reference' => 'ASME B31.3-2024 §328.5.4(f)',
                'minimum_throat_mm' => $headerThroat,
                'equal_leg_equivalent_mm' => $equalLeg($headerThroat),
                'a4_credit_mm2' => 2.0 * $headerThroat ** 2,
            ],
            'pad_to_branch' => [
                'reference' => 'ASME B31.3-2024 §328.5.4(e)(2)',
                'minimum_throat_mm' => $branchThroat,
                'equal_leg_equivalent_mm' => $equalLeg($branchThroat),
                'a4_credit_mm2' => 0.0,
                'credited_in_area_balance' => false,
            ],
        ];
    }
}
