<?php

declare(strict_types=1);

namespace App\Support\Reference\Exceptions;

use RuntimeException;

/**
 * Thrown when a material, stress value, quality factor or factor table entry
 * is asked for that the B31.3 materials reference pack does not define.
 *
 * Same philosophy as UnknownSizeException (ADR-007 section 5): a silent null
 * becomes a silent zero stress, which is unconditionally unsafe in a wall
 * thickness or reinforcement check.
 */
final class UnknownMaterialException extends RuntimeException
{
    public static function material(string $spec, string $grade, ?string $psl = null): self
    {
        $label = trim("{$spec} {$grade}" . ($psl !== null ? " {$psl}" : ''));

        return new self("Unknown B31.3 Table A-1 material '{$label}'. It is not in the reference pack.");
    }

    public static function pslRequired(string $spec, string $grade, array $levels): self
    {
        $list = implode(', ', $levels);

        return new self(
            "Material '{$spec} {$grade}' is published in several levels ({$list}); "
            . "pass the PSL level explicitly."
        );
    }

    public static function temperatureAboveTable(string $material, float $tempC, float $lastColumnC): self
    {
        return new self(
            "Design temperature {$tempC} deg C for '{$material}' exceeds the last tabulated "
            . "column ({$lastColumnC} deg C) of Table A-1; above that column the code requires "
            . "time-dependent rules, not this pack."
        );
    }

    public static function temperatureAboveMax(string $material, float $tempC, float $maxTempC): self
    {
        return new self(
            "Design temperature {$tempC} deg C for '{$material}' exceeds its Table A-1 "
            . "maximum use temperature of {$maxTempC} deg C."
        );
    }

    public static function noStressAt(string $material, float $tempC): self
    {
        return new self("No tabulated stress value for '{$material}' at {$tempC} deg C.");
    }

    public static function ej(string $spec, ?string $hint): self
    {
        $label = $hint !== null ? "{$spec} / {$hint}" : $spec;

        return new self("No Table A-3 quality factor Ej entry for '{$label}'.");
    }

    public static function ambiguousEj(string $spec, int $count): self
    {
        return new self(
            "{$count} Table A-3 entries match '{$spec}'; pass a type or a description "
            . "fragment to pick one."
        );
    }

    public static function factorRow(string $table, string $key): self
    {
        return new self("No row '{$key}' in {$table} of the materials reference pack.");
    }

    public static function factorNotApplicable(string $table, string $key, float $tempC): self
    {
        return new self("{$table} row '{$key}' does not cover {$tempC} deg C (tabulated entry is a dash).");
    }
}
