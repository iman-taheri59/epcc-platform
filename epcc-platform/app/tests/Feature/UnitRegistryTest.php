<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Units\Exceptions\IncompatibleUnitException;
use App\Support\Units\Exceptions\UnknownUnitException;
use App\Support\Units\Quantity;
use App\Support\Units\UnitRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Units of measure and conversion (ADR-014).
 *
 * Pure unit tests - no database. What is at stake is that a pressure or
 * temperature printed on a drawing means the same thing everywhere it is
 * reused: in a stress report, a hydrotest package, a material requisition.
 * A conversion that is quietly wrong does not fail, it just produces a
 * different number that still looks like an engineering value.
 */
final class UnitRegistryTest extends TestCase
{
    private UnitRegistry $units;

    protected function setUp(): void
    {
        parent::setUp();
        $this->units = app(UnitRegistry::class);
    }

    // ------------------------------------------- temperature is affine --

    /**
     * The single most important property here.
     *
     * A converter written with a factor alone - the shape that works for every
     * other quantity in the pack - is wrong by exactly 32 degrees at every
     * point. Verified before writing the code: the naive form turns 0 C into
     * 0 F. These anchors are the ones an engineer would check by hand.
     *
     */
    #[DataProvider('temperatureAnchors')]
    public function test_temperature_converts_affinely(float $value, string $from, float $expected, string $to): void
    {
        $this->assertEqualsWithDelta(
            $expected,
            $this->units->convert($value, $from, $to),
            1e-9,
            "{$value} {$from} should be {$expected} {$to}",
        );
    }

    public static function temperatureAnchors(): array
    {
        return [
            'freezing F to C' => [32.0, 'F', 0.0, 'C'],
            'boiling F to C' => [212.0, 'F', 100.0, 'C'],
            'the crossover' => [-40.0, 'F', -40.0, 'C'],
            'body heat' => [98.6, 'F', 37.0, 'C'],
            'freezing C to F' => [0.0, 'C', 32.0, 'F'],
            'boiling C to F' => [100.0, 'C', 212.0, 'F'],
            'absolute zero K to C' => [0.0, 'K', -273.15, 'C'],
            'ice point K to C' => [273.15, 'K', 0.0, 'C'],
            'C to K' => [25.0, 'C', 298.15, 'K'],
            'K to F' => [255.372222222222, 'K', 0.0, 'F'],
        ];
    }

    public function test_a_temperature_difference_uses_the_factor_only(): void
    {
        // The offset cancels in a subtraction. A 50 C rise is 90 F of rise -
        // calling convert() here would give 122, which is a point on the
        // scale, not an interval. Nothing uses this yet; it exists so the
        // first delta field finds the right tool.
        $this->assertEqualsWithDelta(90.0, $this->units->convertDifference(50.0, 'C', 'F'), 1e-9);
        $this->assertEqualsWithDelta(50.0, $this->units->convertDifference(90.0, 'F', 'C'), 1e-9);

        // And a Kelvin interval equals a Celsius interval exactly.
        $this->assertEqualsWithDelta(50.0, $this->units->convertDifference(50.0, 'C', 'K'), 1e-9);
    }

    public function test_the_point_and_difference_conversions_genuinely_differ(): void
    {
        // Guards against someone "simplifying" convertDifference() into an
        // alias of convert(). If these two ever agree, the distinction has
        // been lost.
        $this->assertNotEqualsWithDelta(
            $this->units->convert(50.0, 'C', 'F'),
            $this->units->convertDifference(50.0, 'C', 'F'),
            1.0,
        );
    }

    // ------------------------------------------------------- pressure --

    #[DataProvider('pressureAnchors')]
    public function test_pressure_factors_match_published_values(float $value, string $from, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, $this->units->convert($value, $from, 'kPa'), 1e-6);
    }

    public static function pressureAnchors(): array
    {
        return [
            '1 psi (NIST)' => [1.0, 'psi', 6.89475729],
            '1 bar is exactly 100 kPa' => [1.0, 'bar', 100.0],
            '1 kgf/cm2 is exactly 98.0665 kPa' => [1.0, 'kg/cm2', 98.0665],
            '1 MPa' => [1.0, 'MPa', 1000.0],
            '1 mmH2O' => [1.0, 'mmH2O', 0.00980665],
            'a 150# flange at 285 psi' => [285.0, 'psi', 1965.0058277],
        ];
    }

    public function test_gauge_and_absolute_are_not_silently_interconverted(): void
    {
        // barg and bara carry the same factor, so the conversion LOOKS like a
        // no-op and is exactly the kind of thing that gets "helpfully" allowed.
        // The offset between them is the site's atmospheric pressure - a
        // function of altitude and weather, not a constant - so converting
        // would fabricate an engineering judgement and bury it in a number.
        $this->expectException(IncompatibleUnitException::class);

        $this->units->convert(10.0, 'barg', 'bara');
    }

    public function test_the_refusal_explains_itself(): void
    {
        try {
            $this->units->convert(10.0, 'barg', 'bara');
            $this->fail('expected a refusal');
        } catch (IncompatibleUnitException $e) {
            $this->assertStringContainsString('atmospheric', $e->getMessage());
        }
    }

    public function test_same_datum_pressure_conversion_still_works(): void
    {
        // The counterpart: the datum rule must not block ordinary work.
        $this->assertEqualsWithDelta(
            10.0,
            $this->units->convert(1000.0, 'kPa', 'barg'),
            1e-9,
        );
    }

    public function test_quantities_cannot_be_crossed(): void
    {
        $this->expectException(IncompatibleUnitException::class);

        $this->units->convert(100.0, 'C', 'psi');
    }

    public function test_the_quantity_check_works_independently_of_the_datum_check(): void
    {
        // Mutation M28: 'C' to 'psi' trips BOTH guards - different quantities
        // AND NONE vs GAUGE datum - so the quantity check could be deleted
        // entirely with the suite still green. Exactly the shape of the AFC
        // gate defect in HANDOFF section 5r.
        //
        // Two quantities that share a datum isolate it: mm and kg are both
        // datum NONE, so only the quantity comparison can refuse this.
        $this->expectException(IncompatibleUnitException::class);

        $this->units->convert(100.0, 'mm', 'kg');
    }

    public function test_the_datum_check_works_independently_of_the_quantity_check(): void
    {
        // The mirror image, so neither guard can hide behind the other:
        // barg and bara are the SAME quantity, so only the datum comparison
        // can refuse this one.
        $this->expectException(IncompatibleUnitException::class);

        $this->units->convert(10.0, 'barg', 'bara');
    }

    // --------------------------------------------------- round-tripping --

    /**
     * Converting to a unit and back must return the original number.
     *
     */
    #[DataProvider('roundTrips')]
    public function test_conversions_round_trip(float $value, string $a, string $b): void
    {
        $there = $this->units->convert($value, $a, $b);
        $back = $this->units->convert($there, $b, $a);

        $this->assertEqualsWithDelta($value, $back, 1e-9, "{$value} {$a} -> {$b} -> {$a}");
    }

    public static function roundTrips(): array
    {
        return [
            [740.0, 'psi', 'kPa'],
            [285.0, 'psi', 'bar'],
            [10.0, 'barg', 'kPa'],
            [150.0, 'C', 'F'],
            [-196.0, 'C', 'K'],
            [6.0, 'in', 'mm'],
            [42.0, 'm3/h', 'USgpm'],
            [1000.0, 'bbl/d', 'm3/h'],
        ];
    }

    public function test_an_unchanged_unit_is_returned_untouched(): void
    {
        // Short-circuited before any arithmetic, so the value is bit-identical
        // rather than merely close. Without this, 1/3 bar through a multiply
        // and a divide comes back as a different float.
        $third = 1.0 / 3.0;

        $this->assertSame($third, $this->units->convert($third, 'bar', 'bar'));
    }

    // ------------------------------------------------ writing units down --

    #[DataProvider('aliasForms')]
    public function test_a_unit_is_recognised_however_it_was_written(string $written, string $expected): void
    {
        // Imports and hand entry are full of these. Rejecting them would push
        // normalisation into every caller, and one caller would forget.
        $this->assertSame($expected, $this->units->resolve($written)['code']);
    }

    public static function aliasForms(): array
    {
        return [
            ['BARG', 'barg'],
            ['bar g', 'barg'],
            ['bar-g', 'barg'],
            ['  barg  ', 'barg'],
            ['PSIG', 'psi'],
            ['psi', 'psi'],
            ['degC', 'C'],
            ['°C', 'C'],
            ['KELVIN', 'K'],
            ['"', 'in'],
            ['inch', 'in'],
            ['kgf/cm2', 'kg/cm2'],
            ['GPM', 'USgpm'],
            ['MT', 't'],
        ];
    }

    public function test_the_inch_and_foot_symbols_do_not_collide(): void
    {
        // Normalisation strips spaces and hyphens but must NOT strip
        // punctuation generally: " and ' are themselves unit symbols, and a
        // blanket strip would fold both onto the empty string.
        $this->assertSame('in', $this->units->resolve('"')['code']);
        $this->assertSame('ft', $this->units->resolve("'")['code']);
    }

    public function test_an_unknown_unit_throws_rather_than_returning_null(): void
    {
        // A silently ignored unit turns "10 psi" into "10" of something, and
        // the bare number keeps flowing through reports looking legitimate.
        $this->expectException(UnknownUnitException::class);

        $this->units->resolve('furlongs');
    }

    public function test_the_unknown_unit_message_names_the_unit(): void
    {
        try {
            $this->units->resolve('furlongs');
            $this->fail('expected a refusal');
        } catch (UnknownUnitException $e) {
            $this->assertStringContainsString('furlongs', $e->getMessage());
        }
    }

    public function test_knows_reports_without_throwing(): void
    {
        $this->assertTrue($this->units->knows('barg'));
        $this->assertFalse($this->units->knows('furlongs'));
    }

    // ------------------------------------------------------- measurement --

    public function test_a_measurement_keeps_both_the_entered_and_canonical_value(): void
    {
        // The user's decision, and the reason for it: 740 psi converted to the
        // canonical unit and back at decimal(12,4) precision returns 740.0006.
        // A rating-table lookup for "740" would then miss, and a report would
        // show a figure that appears on no document.
        $m = $this->units->measure(740.0, 'psi');

        $this->assertSame(740.0, $m->value, 'the entered value survives exactly');
        $this->assertSame('psi', $m->unit);
        $this->assertSame(Quantity::PRESSURE, $m->quantity);
        $this->assertSame('kPa', $m->canonicalUnit);
        $this->assertEqualsWithDelta(5102.1204, $m->canonical, 1e-4);
    }

    public function test_measurements_compare_across_units(): void
    {
        // The whole point of the canonical column: 10 barg and 100 psi are
        // comparable without the caller knowing either unit.
        $tenBarg = $this->units->measure(10.0, 'barg');
        $hundredPsi = $this->units->measure(100.0, 'psi');

        $this->assertSame(1, $tenBarg->compareTo($hundredPsi), '10 barg (1000 kPa) exceeds 100 psi (689 kPa)');
        $this->assertSame(-1, $hundredPsi->compareTo($tenBarg));
    }

    public function test_comparing_different_quantities_returns_null_not_zero(): void
    {
        // Returning 0 or false here would read as "equal", which is worse than
        // useless - a filter would silently include every temperature in a
        // pressure range.
        $p = $this->units->measure(10.0, 'barg');
        $t = $this->units->measure(10.0, 'C');

        $this->assertNull($p->compareTo($t));
        $this->assertFalse($p->isComparableTo($t));
    }

    public function test_a_measurement_formats_as_entered(): void
    {
        // Not as the canonical value: the number on the drawing is the one the
        // engineer typed.
        $this->assertSame('740 psi', $this->units->measure(740.0, 'psi')->format());
        $this->assertSame('13.8 barg', $this->units->measure(13.8, 'barg')->format());
    }

    public function test_a_temperature_below_absolute_zero_is_refused(): void
    {
        // Always a transposed sign or a unit mix-up, never a cold service.
        // Catching it at entry costs nothing; catching it in a stress report
        // costs a revision.
        $this->expectException(UnknownUnitException::class);

        $this->units->measure(-300.0, 'C');
    }

    public function test_absolute_zero_itself_is_allowed(): void
    {
        // The boundary must not be off by one: -273.15 C is a real value.
        $m = $this->units->measure(-273.15, 'C');

        $this->assertEqualsWithDelta(-273.15, $m->canonical, 1e-9);
    }

    public function test_the_absolute_zero_check_understands_other_scales(): void
    {
        // -400 F is below absolute zero (-459.67 F) but is a perfectly
        // ordinary-looking number, so the check has to run on the canonical
        // value rather than the entered one.
        $this->expectException(UnknownUnitException::class);

        $this->units->measure(-500.0, 'F');
    }

    public function test_a_legitimate_cryogenic_temperature_passes(): void
    {
        // LNG service at -162 C must not be swept up by the guard above.
        $m = $this->units->measure(-162.0, 'C');

        $this->assertEqualsWithDelta(-162.0, $m->canonical, 1e-9);
    }

    // ------------------------------------------------------- pack shape --

    public function test_every_quantity_has_its_canonical_unit_in_its_own_list(): void
    {
        // A canonical unit missing from the list would make it unselectable in
        // the UI while still being the storage basis.
        foreach ($this->units->quantities() as $quantity) {
            $canonical = $this->units->canonicalUnit($quantity);
            $codes = array_column($this->units->unitsFor($quantity), 'code');

            $this->assertContains($canonical, $codes, "{$quantity}'s canonical unit {$canonical} is not in its unit list");
        }
    }

    public function test_the_canonical_unit_converts_to_itself_as_one(): void
    {
        // Catches a factor typo on the canonical row itself, which would
        // otherwise rescale an entire quantity silently.
        foreach ($this->units->quantities() as $quantity) {
            $canonical = $this->units->canonicalUnit($quantity);

            $this->assertEqualsWithDelta(
                1.0,
                $this->units->toCanonical(1.0, $canonical),
                1e-12,
                "1 {$canonical} should be 1 in canonical terms",
            );
        }
    }

    public function test_every_unit_is_bilingual(): void
    {
        foreach ($this->units->quantities() as $quantity) {
            foreach ($this->units->unitsFor($quantity) as $unit) {
                $this->assertNotSame('', $this->units->label($unit['code'], 'en'), "{$unit['code']} has no English label");
                $this->assertNotSame('', $this->units->label($unit['code'], 'fa'), "{$unit['code']} has no Farsi label");
                $this->assertNotSame(
                    $this->units->label($unit['code'], 'en'),
                    $this->units->label($unit['code'], 'fa'),
                    "{$unit['code']} has the same text for both languages - probably an untranslated placeholder",
                );
            }
        }
    }

    public function test_every_field_in_the_map_names_a_real_quantity(): void
    {
        foreach (Quantity::fieldMap() as $field => $quantity) {
            $this->assertTrue(Quantity::isKnown($quantity), "{$field} maps to unknown quantity {$quantity}");
            $this->assertNotSame([], $this->units->unitsFor($quantity));
        }
    }
}
