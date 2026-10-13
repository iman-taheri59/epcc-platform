<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * ASME B31.3-2024 Table A-1 — the basic allowable stress, S.
 *
 * The number the wall thickness equation turns on, and the one this repo
 * refused to guess at for two slices. Table A-1 joins a material's identity to
 * its stresses across separate page blocks by a line number that **restarts in
 * every material section**, so a mis-join is possible and entirely silent: a
 * wrong S gives a wrong thickness and nothing downstream complains.
 *
 * TWO GATES, AND ONLY ONE OF THEM IS PARSING
 * ------------------------------------------
 * A value is citable here only if the structural join held AND its
 * room-temperature figure independently agrees with the Code's own basis,
 * para. 302.3.2: S = min(UTS/3, two-thirds of yield), computed from tensile
 * and yield figures that came from the ASTM specifications rather than from
 * this table.
 *
 * **77 of 798 materials clear both.** The rest are held, and `stressAt()`
 * returns null for them, because a plausible stress is indistinguishable from
 * a right one. `refusalFor()` says which gate they failed.
 *
 * A value BELOW the basis is ordinary — a class, a weld joint efficiency or a
 * time-dependent criterion governs instead. A value ABOVE it would be
 * impossible, and there are none: that absence is the evidence the join held,
 * because a mis-join scatters in both directions.
 */
final class AllowableStress
{
    private const REF_ID = 'allowable-stress';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /**
     * The allowable stress in kPa at a temperature, or null.
     *
     * kPa to match every other pressure in the platform; Table A-1 prints MPa.
     * Interpolated linearly between tabulated temperatures. Null above the
     * last tabulated temperature — the material has no rating there, and
     * extrapolating an allowable stress is how a line gets specified for a
     * service it cannot hold.
     */
    public function stressAt(string $spec, ?string $grade, float $temperatureC): ?int
    {
        $m = $this->citable($spec, $grade);
        if ($m === null) {
            return null;
        }

        $pts = $m['points'];
        $first = $pts[0];
        $last = $pts[count($pts) - 1];

        if ($temperatureC > (float) $last['temperatureC']) {
            return null;
        }
        if ($temperatureC <= (float) $first['temperatureC']) {
            return (int) round($first['stressMpa'] * 1000);
        }

        foreach ($pts as $i => $p) {
            if ((float) $p['temperatureC'] < $temperatureC) {
                continue;
            }
            $lo = $pts[$i - 1];
            $span = (float) $p['temperatureC'] - (float) $lo['temperatureC'];
            $f = ($temperatureC - (float) $lo['temperatureC']) / $span;
            $mpa = $lo['stressMpa'] + ($p['stressMpa'] - $lo['stressMpa']) * $f;

            return (int) round($mpa * 1000);
        }

        return null;
    }

    /** Why `stressAt()` gave nothing. */
    public function refusalFor(string $spec, ?string $grade, float $temperatureC): ?string
    {
        $held = $this->find($spec, $grade);
        if ($held === null) {
            return 'MATERIAL_NOT_IN_TABLE_A1';
        }
        if (! $held['verified']) {
            return 'HELD_NOT_INDEPENDENTLY_CONFIRMED';
        }
        $last = $held['points'][count($held['points']) - 1];
        if ($temperatureC > (float) $last['temperatureC']) {
            return 'ABOVE_THE_LAST_TABULATED_TEMPERATURE';
        }

        return null;
    }

    /** The explanation a held material carries, for a finding message. */
    public function heldBecause(string $spec, ?string $grade): ?string
    {
        $m = $this->find($spec, $grade);

        return $m === null ? null : $m['heldNotCitableBecause'];
    }

    /**
     * How many materials are citable, and how many merely held.
     *
     * @return array{citable:int,held:int}
     */
    public function coverage(): array
    {
        $c = 0;
        foreach ($this->pack()['materials'] as $m) {
            if ($m['verified']) {
                $c++;
            }
        }

        return ['citable' => $c, 'held' => count($this->pack()['materials']) - $c];
    }

    /** @return array<string,mixed>|null */
    private function citable(string $spec, ?string $grade): ?array
    {
        $m = $this->find($spec, $grade);

        return $m !== null && $m['verified'] ? $m : null;
    }

    /** @return array<string,mixed>|null */
    private function find(string $spec, ?string $grade): ?array
    {
        $spec = strtoupper(trim($spec));
        $grade = $grade === null ? null : strtoupper(trim($grade));

        $sole = null;
        $several = false;

        foreach ($this->pack()['materials'] as $m) {
            if (strtoupper($m['spec']) !== $spec) {
                continue;
            }
            $mg = $m['grade'] === null ? null : strtoupper((string) $m['grade']);
            if ($mg === $grade) {
                return $m;
            }
            // A spec with ONE row and no grade given still resolves; a spec
            // with several does not, because picking the first would hand the
            // caller another grade's stress curve.
            if ($sole === null) {
                $sole = $m;
            } else {
                $several = true;
            }
        }

        return $grade === null && ! $several ? $sole : null;
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/'.self::REF_ID.'.ref.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Reference pack not readable: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Reference pack is not valid JSON: {$path}");
        }

        return $this->pack = $decoded;
    }
}
