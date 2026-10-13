<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClassItem;
use App\Services\PipingClassBody;
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

    private function item(array $overrides = []): PipingClassItem
    {
        return new PipingClassItem(array_replace([
            'component_type' => 'ELBOW', 'qualifier' => '90LR', 'nps' => '6', 'dn' => 150,
            'dimensional_standard' => 'ASME B16.9-2018', 'end_type' => 'BW',
            'description_as_written' => 'Original PMS row, do not rewrite.',
            'source_ref' => 'Demo sheet 2, row 7',
        ], $overrides));
    }

    public function test_pms_consumer_preserves_source_and_returns_provenance_not_cut_length(): void
    {
        $item = $this->item();
        $before = $item->getAttributes();
        $result = app(PipingClassBody::class)->fittingDimensionForItem($item);
        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('CENTRE_TO_END', $result['row']['dimension']);
        self::assertSame('Table 6.1-1', $result['row']['sourceTable']);
        self::assertSame($before, $item->getAttributes());
        self::assertFalse($item->exists);
    }

    public function test_pms_consumer_requires_compatible_standard_end_size_and_explicit_radius(): void
    {
        $cases = [
            [['dimensional_standard' => null], 'STANDARD_UNSPECIFIED'],
            [['dimensional_standard' => 'B16.11'], 'STANDARD_NOT_COVERED'],
            [['dimensional_standard' => 'B16.9-2024'], 'EDITION_NOT_COVERED'],
            [['end_type' => 'SW'], 'END_TYPE_NOT_COVERED'],
            [['end_type' => null], 'END_TYPE_UNSPECIFIED'],
            [['dn' => 100], 'SIZE_CONFLICT'],
            [['nps' => 'n/a'], 'SIZE_INVALID'],
            [['qualifier' => '45'], 'RADIUS_UNSPECIFIED'],
        ];
        foreach ($cases as [$attrs, $reason]) {
            $item = $this->item($attrs);
            $before = $item->getAttributes();
            $result = app(PipingClassBody::class)->fittingDimensionForItem($item);
            self::assertSame($reason, $result['reason']);
            self::assertNull($result['row']);
            self::assertSame($before, $item->getAttributes());
        }
        self::assertSame('AVAILABLE', app(PipingClassBody::class)->fittingDimensionForItem(
            $this->item(['component_type' => 'CAP', 'qualifier' => null, 'dimensional_standard' => 'B16.9']), 10.92, 'Demo approved thickness specification',
        )['status']);
    }

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
