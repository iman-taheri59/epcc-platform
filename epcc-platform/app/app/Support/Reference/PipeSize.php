<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * One row of ASME B36.10M / B36.19M pipe size data.
 *
 * Four size columns ship deliberately (ADR-007 section 9a) and none of them is
 * redundant:
 *
 *   $nps        "2 1/2"  - what the engineer reads and what a line number carries
 *   $npsDecimal 2.5      - the ONLY summable column. Dia-inch of accepted welds
 *                          is computed from this (ADR-010 section 1). Summing
 *                          the label instead is a TypeError, and sorting it puts
 *                          12 before 2 1/2.
 *   $dn         65       - integer key for the int4range size bands in ADR-008
 *   $odMm       73.0     - physical dimension, fixed by the standard
 */
final readonly class PipeSize
{
    public function __construct(
        public string $nps,
        public float $npsDecimal,
        public int $dn,
        public float $odIn,
        public float $odMm,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            nps: (string) $row['nps'],
            npsDecimal: (float) $row['npsDecimal'],
            dn: (int) $row['dn'],
            odIn: (float) $row['odIn'],
            odMm: (float) $row['odMm'],
        );
    }

    /** Label for a picklist: 2 1/2" (DN65). */
    public function label(): string
    {
        return $this->nps.'" (DN'.$this->dn.')';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'nps' => $this->nps,
            'npsDecimal' => $this->npsDecimal,
            'dn' => $this->dn,
            'odIn' => $this->odIn,
            'odMm' => $this->odMm,
        ];
    }
}
