<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Piping\Pcf\PcfAdmission;
use App\Support\Piping\Pcf\PcfParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PcfAdmissionTest extends TestCase
{
    private function intake(): PcfAdmission
    {
        return new PcfAdmission(new PcfParser);
    }

    private function valid(): string
    {
        return "UNITS-BORE INCH\nUNITS-CO-ORDS MM\nPIPELINE-REFERENCE L-1\nPIPE\n    END-POINT 0 0 0 6\n";
    }

    public function test_accepts_explicit_units_and_line_reference_without_database(): void
    {
        $this->assertSame('L-1', $this->intake()->parse($this->valid())->lineNumber());
    }

    #[DataProvider('invalidFiles')]
    public function test_refuses_unsafe_inputs(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->intake()->parse($text);
    }

    public static function invalidFiles(): array
    {
        $valid = "UNITS-BORE INCH\nUNITS-CO-ORDS MM\nPIPELINE-REFERENCE L-1\nPIPE\n    END-POINT 0 0 0 6\n";

        return [
            'missing bore unit' => [str_replace("UNITS-BORE INCH\n", '', $valid)],
            'missing coordinate unit' => [str_replace("UNITS-CO-ORDS MM\n", '', $valid)],
            'unknown coordinate unit' => [str_replace('UNITS-CO-ORDS MM', 'UNITS-CO-ORDS FURLONG', $valid)],
            'missing line reference' => [str_replace("PIPELINE-REFERENCE L-1\n", '', $valid)],
            'duplicate units' => ["UNITS-BORE MM\n".$valid],
            'non-finite number' => [str_replace('0 0 0 6', '1e999 0 0 6', $valid)],
            'zero bore' => [str_replace('0 0 0 6', '0 0 0 0', $valid)],
            'nul byte' => [$valid."\0"],
            'byte limit' => [str_repeat('x', PcfAdmission::MAX_BYTES + 1)],
            'line limit' => [str_repeat("\n", PcfAdmission::MAX_LINES)],
            'component limit' => ["UNITS-BORE INCH\nUNITS-CO-ORDS MM\nPIPELINE-REFERENCE L-1\n".str_repeat("PIPE\n", PcfAdmission::MAX_COMPONENTS + 1)],
        ];
    }

    /**
     * A PCF that ties into another line, or sets a branch on a header wall,
     * used to import with zero warnings and be read as an ordinary run of the
     * file's own line. A silently reshaped model is worse than a refused one:
     * a refusal sends someone to look.
     */
    private function file(string $body): string
    {
        return "UNITS-BORE INCH\nUNITS-CO-ORDS MM\nPIPELINE-REFERENCE L-1\n".$body;
    }

    /** @return list<string> */
    private function codes(string $body): array
    {
        return array_map(
            static fn ($w): string => $w->code,
            $this->intake()->parse($this->file($body))->warnings,
        );
    }

    public function test_a_plain_run_reports_nothing(): void
    {
        $this->assertSame([], $this->codes("PIPE\n    END-POINT 0 0 0 6 BW\n"));
    }

    public function test_a_component_on_another_line_is_reported_not_swallowed(): void
    {
        $codes = $this->codes("PIPE\n    PIPELINE-REFERENCE L-2\n    END-POINT 0 0 0 6 BW\n");

        $this->assertContains('cross_line_component', $codes);
    }

    public function test_the_other_line_reference_is_carried_in_the_detail(): void
    {
        $warnings = $this->intake()
            ->parse($this->file("PIPE\n    PIPELINE-REFERENCE L-2\n    END-POINT 0 0 0 6 BW\n"))
            ->warnings;

        $cross = array_values(array_filter($warnings, static fn ($w): bool => $w->code === 'cross_line_component'));

        $this->assertCount(1, $cross);
        $this->assertSame('L-2', $cross[0]->detail);
    }

    public function test_a_component_repeating_the_files_own_line_is_not_reported(): void
    {
        // Case is not a difference: exporters disagree on it, and warning
        // here would train everyone to ignore the warning.
        $this->assertSame([], $this->codes("PIPE\n    PIPELINE-REFERENCE l-1\n    END-POINT 0 0 0 6 BW\n"));
    }

    public function test_a_standard_separate_tap_on_a_header_is_reported(): void
    {
        $codes = $this->codes(
            "PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 3000 0 0 6 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER HEADER-1\n"
            ."TAP-CONNECTION\n    CO-ORDS 1500 0 0 3 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER HEADER-1\n"
        );

        $this->assertContains('tap_connection', $codes);
        $this->assertNotContains('tap_host_not_found', $codes);
        $this->assertNotContains('tap_host_reference_missing', $codes);
    }

    public function test_a_component_associated_tap_is_recognized_without_a_host_uci(): void
    {
        $codes = $this->codes(
            "PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 3000 0 0 6 BW\n"
            ."    TAP-CONNECTION CO-ORDS 1500 152 0 3 BW\n"
        );

        $this->assertContains('tap_connection', $codes);
        $this->assertNotContains('tap_host_reference_missing', $codes);
    }

    public function test_a_separate_tap_without_its_host_uci_is_reported_as_unresolved(): void
    {
        $codes = $this->codes("TAP-CONNECTION\n    CO-ORDS 1500 0 0 3 BW\n");

        $this->assertContains('tap_connection', $codes);
        $this->assertContains('tap_host_reference_missing', $codes);
    }

    public function test_a_separate_tap_with_a_dangling_host_uci_is_reported(): void
    {
        $codes = $this->codes(
            "TAP-CONNECTION\n    CO-ORDS 1500 0 0 3 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER NO-SUCH-HOST\n"
        );

        $this->assertContains('tap_host_not_found', $codes);
    }

    public function test_a_tap_host_uci_that_matches_two_components_is_ambiguous(): void
    {
        $codes = $this->codes(
            "PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 3000 0 0 6 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER DUPLICATE-HOST\n"
            ."PIPE\n    END-POINT 3000 0 0 6 BW\n    END-POINT 6000 0 0 6 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER DUPLICATE-HOST\n"
            ."TAP-CONNECTION\n    CO-ORDS 1500 0 0 3 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER DUPLICATE-HOST\n"
        );

        $this->assertContains('tap_host_ambiguous', $codes);
    }

    public function test_a_tap_without_coordinates_is_not_silently_accepted(): void
    {
        $codes = $this->codes(
            "PIPE\n    END-POINT 0 0 0 6 BW\n    UNIQUE-COMPONENT-IDENTIFIER HEADER-1\n"
            ."TAP-CONNECTION\n    UNIQUE-COMPONENT-IDENTIFIER HEADER-1\n"
        );

        $this->assertContains('tap_connection_missing_coordinates', $codes);
    }

    public function test_cross_branch_types_and_generic_olet_are_not_silently_admitted(): void
    {
        foreach (['CROSS-SET-ON', 'CROSS-STUB', 'OLET'] as $type) {
            $this->assertContains(
                'branch_type_unresolved',
                $this->codes($type."\n    CENTRE-POINT 1500 0 0 6\n"),
                $type.' must remain visible until a safe mapping exists.',
            );
        }
    }

    public function test_the_invented_tap_point_spelling_is_not_admitted_as_a_tap(): void
    {
        foreach ([
            "PIPE\n    END-POINT 1500 0 0 3 PL\n    TAP-POINT 1500 152 0 6\n",
            "TAP-POINT\n    CO-ORDS 1500 152 0 6\n",
        ] as $body) {
            $codes = $this->codes($body);

            $this->assertContains('unsupported_tap_point', $codes);
            $this->assertNotContains('tap_connection', $codes);
        }
    }

    public function test_a_set_on_branch_component_is_reported_from_its_skey(): void
    {
        $this->assertContains(
            'branch_on_header_wall',
            $this->codes(
                "TEE-SET-ON\n    END-POINT 0 0 0 6 BW\n"
                ."    CENTRE-POINT 1500 0 0 6\n    SKEY TESO\n"
            ),
        );
    }

    public function test_a_tee_stub_without_its_disambiguating_skey_is_not_guessed(): void
    {
        $this->assertContains(
            'branch_type_unresolved',
            $this->codes("TEE-STUB\n    CENTRE-POINT 1500 0 0 6\n"),
        );
    }

    public function test_a_real_fitting_is_not_reported(): void
    {
        // The point of the check: an ordinary tee is a purchased fitting with
        // a real branch port, and is perfectly representable. Warning on it
        // would bury the two cases that are not.
        $this->assertSame(
            [],
            $this->codes("TEE\n    END-POINT 0 0 0 6 BW\n    END-POINT 3000 0 0 6 BW\n    BRANCH1-POINT 1500 0 0 3 BW\n"),
        );
    }

    public function test_warnings_from_the_parser_itself_survive(): void
    {
        // The intake adds to the parser's list, it does not replace it.
        $warnings = $this->intake()->parse(
            "    ITEM-CODE ORPHAN\n"
            .$this->file(
                "PIPE\n    PIPELINE-REFERENCE L-2\n    END-POINT 0 0 0 6 BW\n"
                ."TAP-CONNECTION\n    CO-ORDS 0 152 0 3 BW\n"
                ."    UNIQUE-COMPONENT-IDENTIFIER MISSING-HOST\n"
            ),
        )->warnings;

        $codes = array_map(static fn ($warning): string => $warning->code, $warnings);

        $this->assertContains('orphan_attribute', $codes);
        $this->assertContains('cross_line_component', $codes);
        $this->assertContains('tap_connection', $codes);
    }
}
