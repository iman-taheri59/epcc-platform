<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClassItem;
use App\Services\PipingClassBody;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use Tests\TestCase;

final class B169SizeScopeTest extends TestCase
{
    public function test_standard_boundary_is_not_the_old_import_boundary(): void
    {
        $ref = app(FittingDimensions::class);
        foreach (['1/2', '0.5', '۱/۲', '24', '26', '28', '۴۸', '48"'] as $nps) {
            self::assertSame('WITHIN_STANDARD_RANGE', $ref->sizeScope($nps));
        }
        foreach (['1/4', '0.499', '48.01', '50', '۵۲', '100/2', '52-1/2'] as $nps) {
            self::assertSame('PROJECT_SPEC_REQUIRED', $ref->sizeScope($nps));
            $result = $ref->lookup('ELBOW', '90LR', $nps);
            self::assertSame('PROJECT_SPEC_REQUIRED', $result['reason']);
            self::assertNull($result['row']);
        }
        foreach (['', '0', '-1', '-1/2', '1/0', '6x4', 'NaN', '52 inches'] as $nps) {
            self::assertSame('SIZE_INVALID', $ref->sizeScope($nps));
        }
    }

    public function test_source_backed_elbow_dimensions_through_48(): void
    {
        $ref = app(FittingDimensions::class);
        foreach ([['28',1067,438], ['36',1372,565], ['48',1829,759]] as [$nps,$a,$b]) {
            foreach ([['90LR',$a],['45LR',$b]] as [$q,$value]) {
                $r = $ref->lookup('ELBOW', $q, $nps);
                self::assertSame('AVAILABLE', $r['status']);
                self::assertEquals($value, $r['row']['mm']);
                self::assertSame(48, $r['source']['sizeRange']['maxNps']);
            }
        }
    }

    public function test_unloaded_size_within_range_is_not_mislabelled_outside_standard(): void
    {
        $r = app(FittingDimensions::class)->lookup('ELBOW', '90LR', '7');
        self::assertSame('REFERENCE_SIZE_NOT_LOADED', $r['reason']);
        self::assertNull($r['row']);
    }

    public function test_absent_row_is_an_ingestion_gap_not_outside_standard(): void
    {
        $pack = json_decode(file_get_contents(base_path('../standards-packs/reference/fitting-dimensions.ref.json')), true);
        $pack['dimensions'] = array_values(array_filter($pack['dimensions'],
            fn ($row) => ! ($row['nps'] === '48' && $row['qualifier'] === 'LONG_RADIUS_90')));
        $dir = sys_get_temp_dir().'/epcc-range-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $file = $dir.'/fitting-dimensions.ref.json';
        try {
            file_put_contents($file, json_encode($pack));
            $ref = new FittingDimensions($dir, app(PipeReference::class));
            self::assertSame('WITHIN_STANDARD_RANGE', $ref->sizeScope('48'));
            self::assertSame('REFERENCE_DATA_NOT_LOADED', $ref->lookup('ELBOW', '90LR', '48')['reason']);
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }

    public function test_large_caps_are_in_range_but_require_agreement_not_an_invented_e1(): void
    {
        foreach (['26','36','48'] as $nps) {
            $ref = app(FittingDimensions::class);
            self::assertSame('WITHIN_STANDARD_RANGE', $ref->sizeScope($nps));
            foreach ([null, 1.0, 50.0] as $thickness) {
                $r = $ref->lookup('CAP', null, $nps, $thickness);
                self::assertSame('CAP_MANUFACTURER_AGREEMENT_REQUIRED', $r['reason']);
                self::assertNull($r['row']);
            }
        }
    }

    public function test_pms_routes_custom_size_to_project_spec_without_mutation_or_dn_inference(): void
    {
        $item = new PipingClassItem(['nps'=>'52','dn'=>1300, 'component_type'=>'ELBOW',
            'qualifier'=>'90LR', 'dimensional_standard'=>'B16.9', 'end_type'=>'BW']);
        $before = $item->getAttributes();
        $r = app(PipingClassBody::class)->fittingDimensionForItem($item);
        self::assertSame('PROJECT_SPEC_REQUIRED', $r['reason']);
        self::assertNull($r['row']);
        self::assertSame($before, $item->getAttributes());
    }
}
