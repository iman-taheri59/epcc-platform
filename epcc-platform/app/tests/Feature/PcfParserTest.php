<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Piping\Pcf\PcfParser;
use App\Support\Piping\Pcf\PcfVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The PCF parser, tested as a pure unit - no database, no tenant, no seeder.
 *
 * Deliberately extends PHPUnit's TestCase rather than the application one.
 * Parsing bugs and import bugs have different causes, and a parser that needed
 * a migrated database to test would be a parser nobody ran often enough.
 */
final class PcfParserTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/pcf/line-6-P.pcf');
    }

    private function parse(?string $text = null)
    {
        return (new PcfParser)->parse($text ?? $this->fixture());
    }

    public function test_it_reads_the_header(): void
    {
        $file = $this->parse();

        $this->assertSame('6-P-A1-PH-14-SS-2201-001', $file->lineNumber());
        $this->assertSame('A1', $file->header('PIPING-SPEC'));
        $this->assertSame('B', $file->header('REVISION'));
    }

    public function test_the_bore_unit_is_read_not_assumed(): void
    {
        // The single most dangerous field in the format. INCH-SIXTEENTH read
        // as INCH makes a 6" line 96" and every downstream number stays
        // plausible, so there is no default anywhere in the code.
        $this->assertSame('INCH', $this->parse()->unitsBore());
        $this->assertNull($this->parse("PIPE\n    END-POINT 0 0 0 6\n")->unitsBore());
    }

    public function test_mm_hundredths_coordinates_are_scaled(): void
    {
        // AVEVA emits this. Read as plain millimetres, a 3 m pipe becomes
        // 300 m and the joint finder matches nothing.
        $file = $this->parse("UNITS-BORE INCH\nUNITS-CO-ORDS MM-HUNDREDTHS\nPIPE\n    END-POINT 0 0 0 6\n");

        $this->assertSame(0.01, $file->coordinateScaleMm());
    }

    public function test_it_separates_components_from_welds(): void
    {
        $file = $this->parse();

        $this->assertCount(6, $file->materialComponents()); // 5 + SUPPORT
        $this->assertCount(2, $file->welds());
    }

    public function test_a_weld_states_shop_or_field(): void
    {
        // The split construction actually cares about, and PCF gives it for
        // free: CATEGORY FABRICATION is shop, ERECTION is field. Guessing it
        // from spool boundaries is how other systems get it wrong.
        $welds = $this->parse()->welds();

        $this->assertSame('SHOP', $welds[0]->weldCategory());
        $this->assertSame('FIELD', $welds[1]->weldCategory());
    }

    public function test_keypoints_carry_bore_and_end_type(): void
    {
        $pipe = $this->parse()->materialComponents()[0];
        $kp = $pipe->connectingKeypoints()[0];

        $this->assertSame(6.0, $kp->bore);
        $this->assertSame('BW', $kp->endType);
        $this->assertSame(0.0, $kp->x);
    }

    public function test_a_keypoint_without_a_bore_is_accepted(): void
    {
        // Exporters omit the bore on components that inherit it. A parser
        // demanding five tokens would reject real files.
        $file = $this->parse("UNITS-BORE INCH\nPIPE\n    END-POINT 0 0 0\n");
        $kp = $file->materialComponents()[0]->connectingKeypoints()[0];

        $this->assertNull($kp->bore);
        $this->assertTrue($kp->hasCoordinates());
    }

    public function test_a_centre_point_is_not_a_connection(): void
    {
        // An elbow's centre point is geometry for the isometric, not a port.
        // Counting it would invent a third end on a two-ended fitting.
        $elbow = $this->parse()->materialComponents()[1];

        $this->assertCount(3, $elbow->keypoints);
        $this->assertCount(2, $elbow->connectingKeypoints());
    }

    public function test_comments_and_blank_lines_are_ignored(): void
    {
        $file = $this->parse("UNITS-BORE INCH\n\n! a comment\nPIPE\n    END-POINT 0 0 0 6\n");

        $this->assertCount(1, $file->materialComponents());
    }

    public function test_a_utf8_bom_does_not_corrupt_the_first_keyword(): void
    {
        // Windows exports carry one, and it would otherwise make the file look
        // headerless - i.e. UNITS-BORE missing, i.e. refused for the wrong reason.
        $file = $this->parse("\xEF\xBB\xBFUNITS-BORE INCH\nPIPE\n    END-POINT 0 0 0 6\n");

        $this->assertSame('INCH', $file->unitsBore());
    }

    public function test_indented_data_before_any_block_is_reported(): void
    {
        // A genuine orphan: indented content with no block of any kind open.
        // Usually a truncated file or one an editor re-indented. Reported,
        // because silence would make the short import look like a modelling
        // problem rather than a damaged file.
        $file = $this->parse("    ITEM-CODE ORPHAN\n");

        $this->assertCount(1, $file->warnings);
        $this->assertSame('orphan_attribute', $file->warnings[0]->code);
    }

    public function test_an_indented_header_field_is_part_of_the_header(): void
    {
        // Real exporters indent the pipeline header's own fields under
        // PIPELINE-REFERENCE. Read as orphans, the file would look as though
        // it had no piping spec - and the import would then succeed with a
        // blank spec, which is worse than failing.
        $file = $this->parse(
            "UNITS-BORE INCH\nPIPELINE-REFERENCE 6-P-1\n    PIPING-SPEC A1\n    REVISION C\n"
        );

        $this->assertSame('A1', $file->header('PIPING-SPEC'));
        $this->assertSame('C', $file->header('REVISION'));
        $this->assertSame([], $file->warnings);
    }

    public function test_repeated_attributes_are_all_kept(): void
    {
        // BOLT legitimately repeats. Last-one-wins would quietly drop bolts
        // from the take-off.
        $file = $this->parse("UNITS-BORE INCH\nFLANGE\n    BOLT A\n    BOLT B\n");

        $this->assertSame(['A', 'B'], $file->materialComponents()[0]->attributes['BOLT']);
    }

    // ------------------------------------------------------- vocabulary --

    public function test_a_set_on_tee_maps_to_an_olet_not_a_tee(): void
    {
        // Geometrically a stub-in: ONE weld, no fitting. Mapping it to TEE
        // would add two welds and a fitting nobody buys.
        $this->assertSame('OLET', PcfVocabulary::templateFor('TEE-SET-ON'));
        $this->assertSame('TEE', PcfVocabulary::templateFor('TEE'));
    }

    public function test_an_unknown_type_is_not_guessed(): void
    {
        $this->assertNull(PcfVocabulary::templateFor('FLUX-CAPACITOR'));
    }

    public function test_supports_are_recognised_but_not_modelled(): void
    {
        $this->assertTrue(PcfVocabulary::isNotModelled('SUPPORT'));
        $this->assertNull(PcfVocabulary::templateFor('SUPPORT'));
    }

    public function test_the_end_type_falls_back_to_the_last_two_of_the_skey(): void
    {
        $known = ['BW', 'SW', 'FL'];

        $this->assertSame('FL', PcfVocabulary::endTypeFrom(null, 'GTFL', $known));
        $this->assertSame('BW', PcfVocabulary::endTypeFrom('BW', 'GTFL', $known));
    }

    public function test_the_pcf_wildcard_is_not_a_real_end_type(): void
    {
        // '**' means "any end". Stored as though it were a code, it would let
        // the compatibility engine pass a pair it has no evidence about.
        $this->assertNull(PcfVocabulary::endTypeFrom('**', null, ['BW', 'SW', 'FL']));
    }

    public function test_an_end_type_outside_our_vocabulary_is_rejected(): void
    {
        $this->assertNull(PcfVocabulary::endTypeFrom('ZZ', null, ['BW', 'SW', 'FL']));
    }
}
