<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * One Table A-1 (SI) row from ASME B31.3-2024. `pNo` is the ASME Section IX
 * P-Number exactly as the table prints it ("1", "5A", "15E"...); the table
 * publishes no Group number, so that remains a rule-level datum.
 *
 * Immutable value object; the table itself lives in the reference pack JSON.
 * `sMpa` is aligned with `temps` entry by entry and may contain nulls where
 * the code prints "..." (no value published for that temperature).
 */
final class B313Material
{
    /**
     * @param list<int>        $temps     temperature ladder, deg C, ascending
     * @param list<float|null> $sMpa      basic allowable stress S, MPa
     */
    public function __construct(
        public readonly string $spec,
        public readonly string $typeGrade,
        public readonly ?string $psl,
        public readonly string $descriptor,
        public readonly ?string $uns,
        public readonly ?string $pNo,
        public readonly string $minTempC,
        public readonly int $tensileMpa,
        public readonly int $yieldMpa,
        public readonly int $maxTempC,
        public readonly array $temps,
        public readonly array $sMpa,
    ) {
    }

    /** Human-readable identity, e.g. "A312 TP304" or "API 5L L290 PSL2". */
    public function label(): string
    {
        $spec = $this->spec === 'API5L' ? 'API 5L' : $this->spec;
        $label = trim("{$spec} {$this->typeGrade}");

        return $this->psl !== null ? "{$label} {$this->psl}" : $label;
    }
}
