<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Support\Reference\Exceptions\FlangeRatingOutOfRangeException;
use App\Support\Reference\Exceptions\UnknownFlangeMaterialException;

/**
 * Read-only access to the ASME B16.5-2025 flange pressure-temperature
 * ratings reference pack (standards-packs/reference/flange-ratings.ref.json).
 *
 * WHY THIS EXISTS AS A FILE, NOT A TABLE
 * --------------------------------------
 * Same reasoning as PipeReference / MaterialReference (ADR-007): the P-T
 * ratings of B16.5 Table 2 are facts of the standard edition, not tenant
 * data. The pack was extracted from ASME B16.5-2025 by
 * scripts/build_flange_ratings_pack.py with build-time cross-verification
 * against the U.S. Customary twin table.
 *
 * LOOKUP SEMANTICS
 * ----------------
 * - Ratings are STEP values: the row whose t_max_degC is the first one at or
 *   above the requested temperature governs (below the first row the first
 *   row governs — the "Min. to 38 degC" row).
 * - Above the last row of a group's ladder, or for a class the table retires
 *   at that temperature (ellipsis), every lookup THROWS — the standard
 *   publishes no rating there, and inventing one is unsafe.
 * - Units as published by the SI tables: temperature deg C, pressure bar.
 *
 * MEMOISATION: decoded once per process, indexed on first use.
 */
final class FlangeRatingReference
{
    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    // ------------------------------------------------------------- pack ---

    /** Standard edition this pack was extracted from. */
    public function standard(): string
    {
        return (string) $this->pack()['source']['standard'];
    }

    /** The standard B16.5 pressure-class ladder (dimensionless classes). */
    public function classes(): array
    {
        return array_values(array_map(intval(...), $this->pack()['classes_standard']));
    }

    /** @return list<string> every material group this pack carries */
    public function groups(): array
    {
        return array_values(array_map(strval(...), array_keys($this->pack()['groups'])));
    }

    // -------------------------------------------------------- materials ---

    /**
     * Resolve a flange material to its B16.5 material group. The
     * spec+grade key is authoritative (A182 spans many groups); the
     * spec-only key is the fallback for specs with a single group such as
     * A105.
     *
     * @throws UnknownFlangeMaterialException
     */
    public function groupFor(string $spec, ?string $grade = null): string
    {
        $spec = trim($spec);
        $grade = $grade !== null && trim($grade) !== '' ? trim($grade) : null;

        $index = $this->pack()['material_index'];

        if ($grade !== null) {
            $key = "{$spec} {$grade}";
            if (isset($index['by_spec_and_grade'][$key])) {
                return (string) $index['by_spec_and_grade'][$key];
            }
        }

        if (isset($index['by_spec'][$spec])) {
            return (string) $index['by_spec'][$spec];
        }

        throw UnknownFlangeMaterialException::material($spec, $grade);
    }

    // ---------------------------------------------------------- ratings ---

    /**
     * Maximum working pressure in bar for one material group, pressure
     * class and temperature, per the group's SI rating table.
     *
     * @throws FlangeRatingOutOfRangeException
     */
    public function maxPressureBar(string $group, int $class, float $temperatureC): float
    {
        $groups = $this->pack()['groups'];

        if (! isset($groups[$group])) {
            throw FlangeRatingOutOfRangeException::unknownGroup($group);
        }

        $table = $groups[$group];
        $ladder = array_values(array_map(intval(...), $table['classes']));

        if (! in_array($class, $ladder, true)) {
            throw FlangeRatingOutOfRangeException::unknownClass($class, $ladder);
        }

        foreach ($table['ratings'] as $row) {
            if ($temperatureC <= (float) $row['t_max_degC']) {
                $value = $row['bar'][(string) $class] ?? null;

                if ($value === null) {
                    throw FlangeRatingOutOfRangeException::classUnavailable(
                        (string) $group, $class, (float) $row['t_max_degC']
                    );
                }

                return (float) $value;
            }
        }

        $top = (float) end($table['ratings'])['t_max_degC'];

        throw FlangeRatingOutOfRangeException::temperatureAboveLadder((string) $group, $temperatureC, $top);
    }

    /** Convenience wrapper: the same rating expressed in MPa. */
    public function maxPressureMpa(string $group, int $class, float $temperatureC): float
    {
        return $this->maxPressureBar($group, $class, $temperatureC) / 10.0;
    }

    /**
     * The full rating ladder of one group: [ ['t_max_degC' => .., 'bar' =>
     * {class => value|null}...], ... ] for reporting and diagnostics.
     *
     * @throws FlangeRatingOutOfRangeException
     *
     * @return list<array{t_max_degC: float, bar: array<string, float|null>}>
     */
    public function ladder(string $group): array
    {
        $groups = $this->pack()['groups'];

        if (! isset($groups[$group])) {
            throw FlangeRatingOutOfRangeException::unknownGroup($group);
        }

        return array_values(array_map(
            static fn (array $row): array => [
                't_max_degC' => (float) $row['t_max_degC'],
                'bar' => $row['bar'],
            ],
            $groups[$group]['ratings']
        ));
    }

    // ----------------------------------------------------------- plumbing ---

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack === null) {
            $path = rtrim($this->refDir, '/').'/flange-ratings.ref.json';

            if (! is_file($path)) {
                throw new \RuntimeException("flange-ratings reference pack not found at {$path}");
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                throw new \RuntimeException("flange-ratings reference pack at {$path} is not valid JSON");
            }

            $this->pack = $decoded;
        }

        return $this->pack;
    }
}
