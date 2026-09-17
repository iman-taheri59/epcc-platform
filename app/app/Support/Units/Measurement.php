<?php

declare(strict_types=1);

namespace App\Support\Units;

/**
 * A number with a unit, and the canonical value alongside it (ADR-014).
 *
 * WHY BOTH VALUES ARE CARRIED
 * ---------------------------
 * The user's decision, and the measurements back it up. Storing only the
 * canonical value loses the number that is actually printed on the drawing:
 * an ASME 300# flange rated 740 psi, converted to kPa and back at the stored
 * precision of decimal(12,4), returns 740.0006 psi. A rating-table lookup for
 * "740" then misses, and a report shows a figure no document contains.
 *
 * Storing only the entered value makes every comparison a conversion, so a
 * query for "all lines above 10 barg" cannot use an index and cannot even be
 * written in SQL without repeating the unit table.
 *
 * So: `value` + `unit` are what the engineer typed and what the document says;
 * `canonical` is derived, for comparison, sorting and roll-ups. The entered
 * pair is the source of truth - if they ever disagree, the entered pair wins
 * and the canonical value is recomputed.
 */
final readonly class Measurement
{
    public function __construct(
        public float $value,
        public string $unit,
        public string $quantity,
        public float $canonical,
        public string $canonicalUnit,
    ) {}

    /** Is this the same physical quantity as another measurement? */
    public function isComparableTo(self $other): bool
    {
        return $this->quantity === $other->quantity;
    }

    /**
     * Compare on the canonical value.
     *
     * Returns null when the two are not the same quantity - a pressure is
     * neither greater nor less than a temperature, and returning 0 or false
     * there would read as "equal".
     */
    public function compareTo(self $other): ?int
    {
        if (! $this->isComparableTo($other)) {
            return null;
        }

        return $this->canonical <=> $other->canonical;
    }

    /** Render as the engineer entered it, not as the canonical value. */
    public function format(int $decimals = 2): string
    {
        return rtrim(rtrim(number_format($this->value, $decimals, '.', ''), '0'), '.').' '.$this->unit;
    }

    /** @return array{value: float, unit: string, quantity: string, canonical: float, canonical_unit: string} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'canonical' => $this->canonical,
            'canonical_unit' => $this->canonicalUnit,
        ];
    }
}
