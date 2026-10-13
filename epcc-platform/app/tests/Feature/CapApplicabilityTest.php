<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClassItem;
use App\Services\PipingClassBody;
use App\Support\Reference\FittingDimensions;
use Tests\TestCase;

final class CapApplicabilityTest extends TestCase
{
    public function test_cap_without_thickness_cannot_return_an_unconditional_length(): void
    {
        $result = app(FittingDimensions::class)->lookup('CAP', null, '6');
        self::assertSame('UNAVAILABLE', $result['status']);
        self::assertSame('CAP_THICKNESS_REQUIRED', $result['reason']);
        self::assertNull($result['row']);
    }

    public function test_source_backed_six_inch_boundary_and_no_rounding_to_e(): void
    {
        foreach ([[10.91, 'E', 89], [10.92, 'E', 89], [10.920001, 'E1', 102], [15, 'E1', 102]] as [$t, $symbol, $mm]) {
            $r = app(FittingDimensions::class)->lookup('CAP', null, '6', $t);
            self::assertSame('AVAILABLE', $r['status']);
            self::assertSame($symbol, $r['row']['symbol']);
            self::assertEquals($mm, $r['row']['mm']);
            self::assertSame((float) $t, $r['selection']['wallThicknessMm']);
            self::assertEquals(89, $r['selection']['tabulatedEMm']);
            self::assertSame('Table 6.1-10', $r['row']['sourceTable']);
        }
    }

    public function test_invalid_thickness_never_selects_e_or_e1(): void
    {
        foreach ([0.0, -1.0, INF, -INF, NAN] as $t) {
            $r = app(FittingDimensions::class)->lookup('CAP', null, '6', $t);
            self::assertSame('CAP_THICKNESS_INVALID', $r['reason']);
            self::assertNull($r['row']);
        }
    }

    public function test_every_cap_has_both_boundary_branches_even_when_lengths_equal(): void
    {
        $pack = json_decode(file_get_contents(base_path('../standards-packs/reference/fitting-dimensions.ref.json')), true);
        $count = 0;
        foreach ($pack['dimensions'] as $row) {
            if ($row['fitting'] !== 'CAP' || $row['requiresManufacturerAgreement']) { continue; }
            $count++;
            $limit = $row['limitingWallThicknessMm'];
            $e = app(FittingDimensions::class)->lookup('CAP', null, $row['nps'], $limit);
            $e1 = app(FittingDimensions::class)->lookup('CAP', null, $row['nps'], $limit + 0.001);
            self::assertSame('E', $e['row']['symbol']);
            self::assertEquals($row['mm'], $e['row']['mm']);
            self::assertSame('E1', $e1['row']['symbol']);
            self::assertEquals($row['e1Mm'], $e1['row']['mm']);
            self::assertSame($e, app(FittingDimensions::class)->lookup('CAP', null, $row['nps'], $limit));
        }
        self::assertSame(21, $count);
    }

    public function test_large_caps_remain_unavailable_not_guessed_or_treated_as_zero(): void
    {
        foreach (['26', '28', '48'] as $size) {
            $r = app(FittingDimensions::class)->lookup('CAP', null, $size, 20);
            self::assertSame('CAP_MANUFACTURER_AGREEMENT_REQUIRED', $r['reason']);
            self::assertNull($r['row']);
        }
    }

    public function test_pms_requires_explicit_thickness_and_source_never_uses_schedule(): void
    {
        $item = new PipingClassItem([
            'component_type' => 'CAP', 'qualifier' => null, 'nps' => '6', 'dn' => 150,
            'dimensional_standard' => 'B16.9-2018', 'end_type' => 'BW',
            'schedule' => '160', 'description_as_written' => 'Original cap wording',
        ]);
        $before = $item->getAttributes();
        $body = app(PipingClassBody::class);
        self::assertSame('CAP_THICKNESS_REQUIRED', $body->fittingDimensionForItem($item)['reason']);
        self::assertSame('CAP_THICKNESS_SOURCE_REQUIRED', $body->fittingDimensionForItem($item, 11)['reason']);
        self::assertSame('CAP_THICKNESS_SOURCE_REQUIRED', $body->fittingDimensionForItem($item, 11, '  ')['reason']);
        $r = $body->fittingDimensionForItem($item, 11, 'Approved thickness specification, demo row 7');
        self::assertSame('E1', $r['row']['symbol']);
        self::assertEquals(102, $r['row']['mm']);
        self::assertSame('Approved thickness specification, demo row 7', $r['selection']['thicknessSource']);
        self::assertSame($before, $item->getAttributes());
        self::assertFalse($item->exists);
    }
}
