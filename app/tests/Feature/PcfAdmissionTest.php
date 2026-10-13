<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Piping\Pcf\PcfAdmission;
use App\Support\Piping\Pcf\PcfParser;
use InvalidArgumentException;
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

    /** @dataProvider invalidFiles */
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
}
