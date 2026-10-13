<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use RuntimeException;
use Tests\TestCase;

final class FittingDimensionsTest extends TestCase
{
    private function reader(): FittingDimensions
    {
        return app(FittingDimensions::class);
    }

    public function test_all_pack_rows_round_trip_without_reinterpreting_dimensions(): void
    {
        $pack = json_decode(file_get_contents(base_path('../standards-packs/reference/fitting-dimensions.ref.json')), true);
        self::assertCount(99, $pack['dimensions']);
        foreach ($pack['dimensions'] as $row) {
            if ($row['requiresManufacturerAgreement'] ?? false) { continue; }
            $result = $this->reader()->lookup($row['fitting'], $row['qualifier'], $row['nps'], $row['limitingWallThicknessMm'] ?? null);
            self::assertSame('AVAILABLE', $result['status']);
            self::assertSame($row, $result['row']);
            self::assertSame($pack['standard'], $result['source']['standard']);
        }
        self::assertSame($this->reader(), $this->reader());
    }

    public function test_small_elbows_use_table_floor_not_a_radius_formula(): void
    {
        foreach (['1/2', '3/4'] as $size) {
            $row = $this->reader()->lookup('ELBOW', '90LR', $size)['row'];
            self::assertEquals(38, $row['mm']);
            self::assertSame('CENTRE_TO_END', $row['dimension']);
            self::assertSame('A', $row['symbol']);
        }
        self::assertSame('LENGTH', $this->reader()->lookup('CAP', null, '1/2', 4.57)['row']['dimension']);
    }

    public function test_aliases_normalise_size_without_mutating_reference_data(): void
    {
        $expected = $this->reader()->lookup('ELBOW', 'LONG_RADIUS_90', '1 1/4');
        foreach (['1.25', '1-1/4', '۱ ۱/۴'] as $size) {
            self::assertSame($expected, $this->reader()->lookup(' elbow ', '90lr', $size));
        }
        self::assertSame('B', $this->reader()->lookup('ELBOW', '45LR', '6')['row']['symbol']);
    }

    public function test_missing_coverage_is_explicit_and_never_zero_or_guessed(): void
    {
        $cases = [
            ['ELBOW', null, '6', 'QUALIFIER_REQUIRED'],
            ['ELBOW', '45', '6', 'RADIUS_UNSPECIFIED'],
            ['ELBOW', '90SR', '6', 'QUALIFIER_NOT_COVERED'],
            ['ELBOW', '180LR', '6', 'QUALIFIER_NOT_COVERED'],
            ['CAP', '90LR', '6', 'QUALIFIER_NOT_COVERED'],
            ['TEE', null, '6', 'FITTING_NOT_COVERED'],
            ['REDUCER', null, '6', 'FITTING_NOT_COVERED'],
            ['ELBOW', '90LR', '6x4', 'SIZE_INVALID'],
            ['ELBOW', '90LR', '52', 'PROJECT_SPEC_REQUIRED'],
            ['CAP', null, '26', 'CAP_MANUFACTURER_AGREEMENT_REQUIRED'],
        ];
        foreach ($cases as [$type, $qualifier, $size, $reason]) {
            $result = $this->reader()->lookup($type, $qualifier, $size);
            self::assertSame('UNAVAILABLE', $result['status']);
            self::assertSame($reason, $result['reason']);
            self::assertNull($result['row']);
            self::assertSame(2018, $result['source']['standard']['editionYear']);
        }
    }

    // The former PipingClassItem/PipingClassBody consumer tests belonged to
    // the parallel PMS schema, which is not part of this branch. Reader and
    // current project-fitting workflow tests remain active separately.

    public function test_corrupted_packs_fail_loudly_and_do_not_cache_partial_rows(): void
    {
        $original = json_decode(file_get_contents(base_path('../standards-packs/reference/fitting-dimensions.ref.json')), true);
        $dir = sys_get_temp_dir().'/epcc-fitting-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $file = $dir.'/fitting-dimensions.ref.json';
        $mutations = [
            static function (&$p) { $p['units']['length'] = 'in'; },
            static function (&$p) { unset($p['dimensions'][0]['limitingWallThicknessMm']); },
            static function (&$p) { unset($p['dimensions'][0]['e1Mm']); },
            static function (&$p) { $p['dimensions'][0]['limitingWallThicknessMm'] = 0; },
            static function (&$p) { $p['dimensions'][0]['e1Mm'] = 1; },
            static function (&$p) { $p['dimensions'][] = $p['dimensions'][0]; },
            static function (&$p) { $p['dimensions'][1]['dimension'] = 'CENTRE_TO_END'; },
            static function (&$p) { $p['dimensions'][1]['mm'] = 0; },
            static function (&$p) { $p['dimensions'][1]['mm'] = '25'; },
            static function (&$p) { $p['dimensions'][1]['dn'] = 999; },
            static function (&$p) { $p['dimensions'][1]['odMm'] = 999; },
            static function (&$p) { $p['dimensions'][1]['sourceTable'] = 'Table invented'; },
        ];
        try {
            foreach ($mutations as $mutate) {
                $pack = $original;
                $mutate($pack);
                file_put_contents($file, json_encode($pack));
                $reader = new FittingDimensions($dir, app(PipeReference::class));
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    try {
                        $reader->lookup('CAP', null, '1/2');
                        self::fail('Corrupt pack must never produce a dimension.');
                    } catch (RuntimeException $e) {
                        self::assertNotSame('', $e->getMessage());
                    }
                }
            }
            file_put_contents($file, '{broken');
            try {
                (new FittingDimensions($dir, app(PipeReference::class)))->source();
                self::fail('Broken JSON must not appear as missing engineering coverage.');
            } catch (\JsonException) {
                self::assertTrue(true);
            }
            unlink($file);
            $this->expectException(RuntimeException::class);
            (new FittingDimensions($dir, app(PipeReference::class)))->source();
        } finally {
            if (is_file($file)) { unlink($file); }
            rmdir($dir);
        }
    }
}
