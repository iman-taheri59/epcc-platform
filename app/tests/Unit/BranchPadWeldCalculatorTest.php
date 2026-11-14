<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchPadWeldCalculator as Welds;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchPadWeldCalculatorTest extends TestCase
{
    public function test_h302_root_and_pad_edge_weld_areas_match_the_published_example(): void
    {
        // ASME B31.3-2024 Appendix H H302: nominal branch wall 12.7 mm,
        // nominal pipe-saddle wall 17.48 mm. Figure 328.5.4 gives the root
        // throat tc=6 mm and pad-edge throat 0.5 Tr=8.74 mm.
        $welds = Welds::minimums(
            branchNominalWallMm: 12.7,
            padNominalThicknessMm: 17.48,
        );

        $this->assertEqualsWithDelta(6.0, $welds['root_branch_to_run']['minimum_throat_mm'], 1e-12);
        $this->assertEqualsWithDelta(8.48656294, $welds['root_branch_to_run']['equal_leg_equivalent_mm'], 1e-8);
        $this->assertEqualsWithDelta(72.0, $welds['root_branch_to_run']['a4_credit_mm2'], 1e-12);

        $this->assertEqualsWithDelta(8.74, $welds['pad_to_header']['minimum_throat_mm'], 1e-12);
        $this->assertEqualsWithDelta(12.36209335, $welds['pad_to_header']['equal_leg_equivalent_mm'], 1e-8);
        $this->assertEqualsWithDelta(152.7752, $welds['pad_to_header']['a4_credit_mm2'], 1e-9);
        $this->assertEqualsWithDelta(224.7752,
            $welds['root_branch_to_run']['a4_credit_mm2'] + $welds['pad_to_header']['a4_credit_mm2'],
            1e-9,
        );

        // The separately numbered PAD-to-branch fillet uses §328.5.4(e)(2),
        // but is intentionally not relied on for additional area credit.
        $this->assertEqualsWithDelta(8.89, $welds['pad_to_branch']['minimum_throat_mm'], 1e-12);
        $this->assertFalse($welds['pad_to_branch']['credited_in_area_balance']);
        $this->assertSame(0.0, $welds['pad_to_branch']['a4_credit_mm2']);
    }

    public function test_pad_branch_fillet_uses_the_thinner_nominal_member(): void
    {
        $welds = Welds::minimums(
            branchNominalWallMm: 5.54,
            padNominalThicknessMm: 8.5,
        );

        $this->assertEqualsWithDelta(3.878, $welds['pad_to_branch']['minimum_throat_mm'], 1e-12);
        $this->assertEqualsWithDelta(4.25, $welds['pad_to_header']['minimum_throat_mm'], 1e-12);
    }

    public function test_nonpositive_nominal_thickness_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Welds::minimums(branchNominalWallMm: 0.0, padNominalThicknessMm: 6.35);
    }
}
