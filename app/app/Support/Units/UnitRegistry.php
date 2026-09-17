<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Support\Units\Exceptions\IncompatibleUnitException;
use App\Support\Units\Exceptions\UnknownUnitException;
use RuntimeException;

/**
 * Units of measure and conversion between them (ADR-014).
 *
 * Like the pipe reference packs (ADR-007) this reads a versioned JSON file
 * rather than a database table: 1 psi = 6.894757 kPa on every project, in
 * every company, in every year. It is not tenant data, and the protection
 * required is "nobody can write, including the owner company", which a file
 * gives absolutely and an RLS policy only pretends to.
 *
 * TEMPERATURE IS AFFINE, AND THAT IS THE WHOLE DESIGN CONSTRAINT
 * --------------------------------------------------------------
 * Every other quantity here converts by a single factor. Temperature needs a
 * factor AND an offset, and a converter written for the factor alone is wrong
 * by 32 degrees at every single point - measured, not assumed: 0 C becomes
 * 0 F instead of 32 F. So the conversion is expressed as
 *
 *     canonical = value * factor + offset
 *     value     = (canonical - offset) / factor
 *
 * with offset defaulting to 0, which collapses to the plain factor case for
 * pressure, length, mass and flow. One code path, no special-casing by
 * quantity name, and the affine form verified against seven anchor points
 * (32F=0C, 212F=100C, -40F=-40C, 98.6F=37C, 273.15K=0C, 373.15K=100C).
 *
 * A temperature DIFFERENCE is a different animal: it converts with the factor
 * only. There is no delta field in the schema today; if one is added it must
 * not call convert(). See convertDifference().
 *
 * GAUGE AND ABSOLUTE ARE NOT INTERCONVERTED
 * -----------------------------------------
 * barg and bara carry the same factor to kPa but different datums. The
 * difference between them is the local atmospheric pressure, which is a site
 * condition - altitude, weather - not a constant. Converting between them
 * silently would fabricate an engineering judgement, so this class refuses and
 * says why. The user's instruction for now is to treat everything as gauge;
 * this refusal is what makes that assumption safe to revisit later rather than
 * baked invisibly into stored numbers.
 */
final class UnitRegistry
{
    /** @var array<string, mixed>|null */
    private ?array $pack = null;

    /** @var array<string, array<string, array<string, mixed>>>|null  quantity => code => unit */
    private ?array $byQuantity = null;

    /** @var array<string, array{quantity: string, code: string}>|null  lowercased code/alias => target */
    private ?array $aliases = null;

    public function __construct(private readonly string $refDir) {}

    // ------------------------------------------------------------ lookup --

    /** @return list<string> */
    public function quantities(): array
    {
        $this->index();

        return array_keys($this->byQuantity ?? []);
    }

    /**
     * Every unit for a quantity, in pack order (canonical first is not assumed).
     *
     * @return list<array<string, mixed>>
     * @throws UnknownUnitException
     */
    public function unitsFor(string $quantity): array
    {
        $this->index();

        if (! isset($this->byQuantity[$quantity])) {
            throw new UnknownUnitException("There is no quantity '{$quantity}' in the units pack.");
        }

        return array_values($this->byQuantity[$quantity]);
    }

    /** @throws UnknownUnitException */
    public function canonicalUnit(string $quantity): string
    {
        $this->index();

        foreach ((array) ($this->pack['quantities'] ?? []) as $q) {
            if (($q['quantity'] ?? null) === $quantity) {
                return (string) $q['canonical'];
            }
        }

        throw new UnknownUnitException("There is no quantity '{$quantity}' in the units pack.");
    }

    /**
     * Resolve a unit code the way a human wrote it.
     *
     * "BARG", "bar g" and "barg" are the same unit; so are `"`, "in" and
     * "inch". Import files and hand entry are full of these, and rejecting
     * them would push the normalisation into every caller.
     *
     * @return array{quantity: string, code: string}
     * @throws UnknownUnitException
     */
    public function resolve(string $unit): array
    {
        $this->index();

        $key = $this->normalise($unit);

        if (! isset($this->aliases[$key])) {
            throw new UnknownUnitException("'{$unit}' is not a unit this platform knows.");
        }

        return $this->aliases[$key];
    }

    public function knows(string $unit): bool
    {
        try {
            $this->resolve($unit);

            return true;
        } catch (UnknownUnitException) {
            return false;
        }
    }

    /** The canonical code for a unit, e.g. "psi" => "kPa". */
    public function canonicalFor(string $unit): string
    {
        return $this->canonicalUnit($this->resolve($unit)['quantity']);
    }

    /** Bilingual label for a unit code. */
    public function label(string $unit, string $lang = 'en'): string
    {
        $u = $this->unit($unit);

        return (string) ($u['label'][$lang] ?? $u['label']['en'] ?? $u['code']);
    }

    // -------------------------------------------------------- conversion --

    /**
     * Convert a value between two units of the same quantity.
     *
     * @throws UnknownUnitException|IncompatibleUnitException
     */
    public function convert(float $value, string $from, string $to): float
    {
        $f = $this->resolve($from);
        $t = $this->resolve($to);

        if ($f['quantity'] !== $t['quantity']) {
            throw new IncompatibleUnitException(
                "Cannot convert {$f['code']} ({$f['quantity']}) to {$t['code']} ({$t['quantity']}): different quantities."
            );
        }

        $fu = $this->unit($from);
        $tu = $this->unit($to);

        $fromDatum = (string) ($fu['datum'] ?? 'NONE');
        $toDatum = (string) ($tu['datum'] ?? 'NONE');

        if ($fromDatum !== $toDatum) {
            // Gauge to absolute needs the local atmospheric pressure, which is
            // a site condition rather than a constant. Doing it silently would
            // invent an engineering decision and bury it in a stored number.
            throw new IncompatibleUnitException(
                "Cannot convert {$fu['code']} to {$tu['code']} automatically: one is gauge and the other absolute, "
                ."and the difference is the site's atmospheric pressure, not a fixed constant."
            );
        }

        if ($from === $to) {
            // Short-circuit before any arithmetic, so an unchanged value is
            // bit-identical rather than merely very close.
            return $value;
        }

        return $this->fromCanonical($this->toCanonical($value, $from), $to);
    }

    /** Value expressed in the quantity's canonical unit. */
    public function toCanonical(float $value, string $unit): float
    {
        $u = $this->unit($unit);

        return $value * (float) $u['factor'] + (float) ($u['offset'] ?? 0.0);
    }

    /** Canonical value expressed in the given unit. */
    public function fromCanonical(float $canonical, string $unit): float
    {
        $u = $this->unit($unit);

        return ($canonical - (float) ($u['offset'] ?? 0.0)) / (float) $u['factor'];
    }

    /**
     * Convert a DIFFERENCE between two temperatures (or any affine quantity).
     *
     * A 50 °C rise is 90 °F of rise, not 122 °F. The offset cancels in a
     * subtraction, so a delta uses the factor alone. Nothing calls this yet -
     * it exists so that the first person to add a "temperature range" field
     * finds the correct tool instead of reaching for convert().
     */
    public function convertDifference(float $delta, string $from, string $to): float
    {
        $f = $this->resolve($from);
        $t = $this->resolve($to);

        if ($f['quantity'] !== $t['quantity']) {
            throw new IncompatibleUnitException(
                "Cannot convert a {$f['quantity']} difference to {$t['quantity']}."
            );
        }

        $fu = $this->unit($from);
        $tu = $this->unit($to);

        return $delta * (float) $fu['factor'] / (float) $tu['factor'];
    }

    /**
     * Build a Measurement, validating the unit and computing the canonical.
     *
     * @throws UnknownUnitException
     */
    public function measure(float $value, string $unit): Measurement
    {
        $resolved = $this->resolve($unit);
        $quantity = $resolved['quantity'];

        $this->assertPhysicallyPossible($value, $unit, $quantity);

        return new Measurement(
            value: $value,
            unit: $resolved['code'],
            quantity: $quantity,
            canonical: $this->toCanonical($value, $unit),
            canonicalUnit: $this->canonicalUnit($quantity),
        );
    }

    /**
     * Refuse a value that cannot exist.
     *
     * Only one such rule today, and it is worth having: a design temperature
     * below absolute zero is a transposed minus sign or a unit mix-up, never a
     * cryogenic service. Catching it at entry costs nothing; catching it in a
     * stress report costs a revision.
     */
    private function assertPhysicallyPossible(float $value, string $unit, string $quantity): void
    {
        if ($quantity !== Quantity::TEMPERATURE) {
            return;
        }

        $min = $this->minimumCanonical($quantity);

        if ($min === null) {
            return;
        }

        $canonical = $this->toCanonical($value, $unit);

        // Tolerance of one ulp-ish: -273.15 itself is legitimate.
        if ($canonical < $min - 1e-9) {
            throw new UnknownUnitException(
                "A temperature of {$value} {$unit} is below absolute zero, which is a data-entry error rather than a cold service."
            );
        }
    }

    private function minimumCanonical(string $quantity): ?float
    {
        $this->index();

        foreach ((array) ($this->pack['quantities'] ?? []) as $q) {
            if (($q['quantity'] ?? null) === $quantity && isset($q['minimum_canonical'])) {
                return (float) $q['minimum_canonical'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------ internals --

    /**
     * @return array<string, mixed>
     * @throws UnknownUnitException
     */
    private function unit(string $unit): array
    {
        $r = $this->resolve($unit);

        return $this->byQuantity[$r['quantity']][$r['code']];
    }

    /**
     * Fold a written unit down to a lookup key.
     *
     * Case and internal spaces/hyphens only - NOT punctuation in general,
     * because `"` (inch) and `'` (foot) are themselves unit symbols and
     * stripping them would make the two collide with the empty string.
     */
    private function normalise(string $unit): string
    {
        return str_replace([' ', '-', '_'], '', mb_strtolower(trim($unit)));
    }

    private function index(): void
    {
        if ($this->byQuantity !== null) {
            return;
        }

        $path = rtrim($this->refDir, '/').'/units.ref.json';

        if (! is_file($path)) {
            throw new RuntimeException("Units pack not found at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Units pack at {$path} is not valid JSON.");
        }

        $this->pack = $decoded;
        $this->byQuantity = [];
        $this->aliases = [];

        foreach ((array) ($decoded['quantities'] ?? []) as $q) {
            $quantity = (string) $q['quantity'];
            $this->byQuantity[$quantity] = [];

            foreach ((array) ($q['units'] ?? []) as $u) {
                $code = (string) $u['code'];
                $this->byQuantity[$quantity][$code] = $u;

                $target = ['quantity' => $quantity, 'code' => $code];

                foreach (array_merge([$code], (array) ($u['aliases'] ?? [])) as $alias) {
                    $key = $this->normalise((string) $alias);

                    // First writer wins, and the canonical code is written
                    // first, so an alias can never shadow a real unit code.
                    if (! isset($this->aliases[$key])) {
                        $this->aliases[$key] = $target;
                    }
                }
            }
        }
    }
}
