<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * How long a valve is — ASME B16.10-2022 (with the March 2023 errata).
 *
 * B16.34 says how much pressure a valve holds. It says nothing at all about how
 * long it is, and a length is what a drawing needs. Without this pack a spool
 * cannot be dimensioned, an isometric cannot be closed, and the pipe length on
 * an MTO is a guess with a decimal point on it.
 *
 * FOUR SYMBOLS, NOT ONE NUMBER
 * ----------------------------
 * `A` face-to-face, flanged. `B` end-to-end, buttwelding. `D` centre-to-face
 * and `E` centre-to-end, for angle valves, which have no face-to-face because
 * they turn a corner. Asking "how long is this valve" without saying which of
 * the four you mean is how a 90-degree body gets treated as an in-line one.
 *
 * A COLUMN CAN SERVE SEVERAL VALVE TYPES
 * --------------------------------------
 * B16.10 groups valves that share a length: "Globe, Lift Check, and Swing
 * Check" is one column, so in Class 600 at NPS 6 all three are 559 mm. That is
 * the standard's statement, not a coincidence, and `valveTypes` is a list
 * because flattening it would lose it.
 *
 * THE FACING CHANGES THE LENGTH
 * -----------------------------
 * The tabulated figures are for the facing each table was drawn for — 1.5 mm
 * raised face in Classes 150 and 300, 6.4 mm from 600 up. A ring joint or a
 * tongue-and-groove face makes the valve a different length, which is what
 * `facingAdjustmentMm()` and `ringJointAdjustment()` are for. A length quoted
 * without its facing is incomplete, and quietly so.
 *
 * WHAT IT REFUSES
 * ---------------
 * Class 4500 is rated by B16.34 and dimensioned by nobody: `lengthFor()`
 * returns null and `refusalFor()` says `CLASS_HAS_NO_TABULATED_LENGTH`. Cast
 * iron is not in the pack at all — B16.34 does not rate it and the material
 * library holds no cast-iron valve body, so a dimension for it could never be
 * paired with anything.
 */
final class ValveFaceToFace
{
    private const REF_ID = 'valve-face-to-face';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /** @return list<string> */
    public function valveTypes(): array
    {
        $out = [];
        foreach ($this->pack()['dimensions'] as $d) {
            foreach ($d['valveTypes'] as $t) {
                $out[$t] = true;
            }
        }
        ksort($out);

        return array_keys($out);
    }

    /** @return list<string> */
    public function pressureClasses(): array
    {
        $out = [];
        foreach ($this->pack()['dimensions'] as $d) {
            if ($d['pressureClass'] !== null) {
                $out[$d['pressureClass']] = true;
            }
        }
        // array_keys on a map with numeric-string keys hands back INTEGERS,
        // and a strict in_array('150', ...) against them is false forever.
        $keys = array_map(strval(...), array_keys($out));
        usort($keys, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $keys;
    }

    /**
     * Every tabulated length for a valve type, class, size and end type.
     *
     * A list rather than one figure, because B16.10 genuinely offers more than
     * one: a Class 600 gate valve comes in a long pattern and a short pattern
     * and they are different lengths. Choosing between them is the project's
     * decision, not this pack's.
     *
     * @return list<array<string,mixed>>
     */
    public function lengthsFor(
        string $valveType,
        string $pressureClass,
        string $nps,
        ?string $endType = null,
    ): array {
        $type = strtoupper(trim($valveType));
        $class = $this->normaliseClass($pressureClass);
        $size = $this->normaliseNps($nps);

        $out = [];
        foreach ($this->pack()['dimensions'] as $d) {
            if (! in_array($type, $d['valveTypes'], true)) {
                continue;
            }
            if ($d['pressureClass'] !== $class) {
                continue;
            }
            if ($this->normaliseNps($d['nps']) !== $size) {
                continue;
            }
            if ($endType !== null && $d['endType'] !== strtoupper($endType)) {
                continue;
            }
            $out[] = $d;
        }

        return $out;
    }

    /**
     * One length in millimetres, or null when the answer is not single-valued.
     *
     * Null here never means "about this much". It means the pack cannot name
     * one figure, and `refusalFor()` says which of the four reasons applies —
     * including `SEVERAL_PATTERNS_OFFERED`, where returning the first would
     * silently pick a pattern the project never chose.
     */
    public function lengthFor(
        string $valveType,
        string $pressureClass,
        string $nps,
        string $endType = 'RF',
        ?string $pattern = null,
        ?string $symbol = null,
    ): ?float {
        $rows = $this->narrow($valveType, $pressureClass, $nps, $endType, $pattern, $symbol);
        $distinct = array_values(array_unique(array_column($rows, 'mm')));

        return count($distinct) === 1 ? (float) $distinct[0] : null;
    }

    /** Why `lengthFor()` gave nothing. */
    public function refusalFor(
        string $valveType,
        string $pressureClass,
        string $nps,
        string $endType = 'RF',
        ?string $pattern = null,
        ?string $symbol = null,
    ): ?string {
        $class = $this->normaliseClass($pressureClass);

        if (! in_array($class, $this->pressureClasses(), true)) {
            return 'CLASS_HAS_NO_TABULATED_LENGTH';
        }
        if (! in_array(strtoupper(trim($valveType)), $this->valveTypes(), true)) {
            return 'VALVE_TYPE_NOT_IN_B1610';
        }

        if ($this->lengthsFor($valveType, $pressureClass, $nps, $endType) === []) {
            return 'NOT_OFFERED_AT_THIS_SIZE_OR_END';
        }

        $rows = $this->narrow($valveType, $pressureClass, $nps, $endType, $pattern, $symbol);
        if ($rows === []) {
            return $symbol !== null ? 'BODY_STYLE_NOT_OFFERED' : 'PATTERN_NOT_OFFERED';
        }

        if (count(array_unique(array_column($rows, 'mm'))) === 1) {
            return null;
        }

        // TWO BODY STYLES BEHIND ONE VALVE TYPE. A lift check appears twice in
        // Table 1.5-1: once with the globes, as an in-line body measured
        // face-to-face (symbol A), and once with the angle valves, measured
        // centre-to-face (symbol D). At Class 600 NPS 6 that is 559 mm against
        // 279 mm - not two patterns of one valve but two different valves. The
        // caller has to say which, and a distinct refusal makes that askable.
        return count(array_unique(array_column($rows, 'symbol'))) > 1
            ? 'SEVERAL_BODY_STYLES_OFFERED'
            : 'SEVERAL_PATTERNS_OFFERED';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function narrow(
        string $valveType,
        string $pressureClass,
        string $nps,
        string $endType,
        ?string $pattern,
        ?string $symbol,
    ): array {
        $rows = $this->lengthsFor($valveType, $pressureClass, $nps, $endType);
        if ($pattern !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => $r['pattern'] === strtoupper($pattern),
            ));
        }
        if ($symbol !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => $r['symbol'] === strtoupper($symbol),
            ));
        }

        return $rows;
    }

    /**
     * The patterns B16.10 offers for a valve type, class and size — the
     * vocabulary a transcription screen should present rather than invent.
     *
     * @return list<array{pattern:string|null,endType:string,symbol:string,mm:float,in:float}>
     */
    public function patternsFor(string $valveType, string $pressureClass, string $nps): array
    {
        $out = [];
        foreach ($this->lengthsFor($valveType, $pressureClass, $nps) as $d) {
            $out[$d['pattern'].'|'.$d['endType'].'|'.$d['symbol']] = [
                'pattern' => $d['pattern'],
                'endType' => $d['endType'],
                'symbol' => $d['symbol'],
                'mm' => (float) $d['mm'],
                'in' => (float) $d['in'],
            ];
        }

        return array_values($out);
    }

    /**
     * How much a facing other than the tabulated one adds to the length.
     *
     * Returns null for `RING_JOINT`, which is not one number: use
     * `ringJointAdjustment()`. Null is also returned for a flat face on Class
     * 150/300, where B16.10 note (4) makes it a supply option rather than a
     * dimension.
     */
    public function facingAdjustmentMm(string $pressureClass, string $facing): ?float
    {
        $class = $this->normaliseClass($pressureClass);
        $facing = strtoupper(trim($facing));

        foreach ($this->pack()['facingAdjustments'] ?? [] as $a) {
            if ($a['facing'] === $facing && in_array($class, $a['pressureClasses'], true)) {
                return $a['adjustmentMm'] === null ? null : (float) $a['adjustmentMm'];
            }
        }

        return null;
    }

    /**
     * Table 3.3-1: X is added to the raised-face face-to-face figure to get the
     * ring joint end-to-end dimension; S is the centre-to-end adjustment.
     *
     * @return array{X:float|null,S:float|null}
     */
    public function ringJointAdjustment(string $pressureClass, string $nps): array
    {
        $class = $this->normaliseClass($pressureClass);
        $size = $this->normaliseNps($nps);
        $out = ['X' => null, 'S' => null];

        foreach ($this->pack()['ringJoint'] ?? [] as $r) {
            if ($r['pressureClass'] === $class && $this->normaliseNps($r['nps']) === $size) {
                $out[$r['symbol']] = (float) $r['mm'];
            }
        }

        return $out;
    }

    /**
     * The whole length of a ring-joint valve, tabulated figure plus X.
     *
     * The one calculation this class does, because it is the one B16.10 tells
     * you to do (Table 3.2-1 note 5) and the one most likely to be forgotten:
     * an RTJ valve is not the length printed in the dimension table.
     */
    public function ringJointLengthFor(
        string $valveType,
        string $pressureClass,
        string $nps,
        ?string $pattern = null,
    ): ?float {
        $base = $this->lengthFor($valveType, $pressureClass, $nps, 'RF', $pattern);
        $x = $this->ringJointAdjustment($pressureClass, $nps)['X'];

        return $base === null || $x === null ? null : $base + $x;
    }

    /**
     * What this pack was checked against, and what came of it.
     *
     * B16.10 says in note (14) that some of its Class 150 gate figures are
     * "extracted from API 6D". API 6D-2021 Annex C is normative and prints
     * them too, so the claim is testable - and holds.
     *
     * @return array<string,mixed>
     */
    public function corroboration(): array
    {
        return $this->pack()['corroboration'] ?? [];
    }

    /** @return array<string,mixed> */
    public function anomalies(): array
    {
        return $this->pack()['anomalies'] ?? [];
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    // ---------------------------------------------------------- internals --

    private function normaliseClass(string $class): string
    {
        return (string) preg_replace('/[^0-9]/', '', $class);
    }

    /** '1 1/2', '1-1/2' and '1.5' are one size written three ways. */
    private function normaliseNps(string $nps): string
    {
        $n = strtoupper(trim($nps));
        $n = (string) preg_replace('/^NPS\s*/', '', $n);
        $n = str_replace('-', ' ', $n);

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $n, $m)) {
            return rtrim(rtrim(number_format((float) $m[1] + (int) $m[2] / (int) $m[3], 4, '.', ''), '0'), '.');
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $n, $m)) {
            return rtrim(rtrim(number_format((int) $m[1] / (int) $m[2], 4, '.', ''), '0'), '.');
        }

        return rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
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
