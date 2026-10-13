<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * ASME B31.3-2024 clause 304.1.2 — internal pressure design thickness.
 *
 * `PipingClassHeader` has been saying *"no B31.3 wall thickness can be
 * calculated"* since step 4 without anyone having read the clause. This is the
 * clause, transcribed:
 *
 *     t  = P·D / (2·(S·E·W + P·Y))          eq. (3a)
 *     tm = t + c
 *
 * WHAT IT REFUSES, AND WHY THAT IS THE POINT
 * ------------------------------------------
 * **S is not in this pack.** Table A-1 holds the basic allowable stresses for
 * some six hundred materials, and its identification rows and its stress
 * values are printed in *separate page blocks* — thirty-five of them in the SI
 * table alone — joined only by a line number. A parse that mis-joins one block
 * gives a material the wrong allowable stress; the thickness comes out wrong;
 * and nothing downstream can tell. So `S` is a parameter the caller supplies
 * from a source it can name, and `calculate()` returns a refusal rather than a
 * number when it is absent. Slice 3 will read Table A-1 properly.
 *
 * **The formula has an edge and the Code says so.** Clause 304.1.2(b): for
 * t ≥ D/6 **or** for P/SE > 0.385, the thin-wall equation stops applying and
 * the Code requires "special consideration of factors such as theory of
 * failure, effects of fatigue, and thermal stress" — which is to say it stops
 * giving a formula. Past that edge this class refuses too. A calculator that
 * keeps multiplying past its own validity limit is worse than no calculator,
 * because the answer still looks like an answer.
 */
final class WallThickness
{
    private const REF_ID = 'fluid-service';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /**
     * The coefficient Y for a material family at a temperature.
     *
     * The first column of Table 304.1.1-1 is "482 °C and below" and the last
     * is "677 °C and above" — open bands, not points — and the Code permits
     * interpolation between them. Null where the table gives nothing: gray
     * iron simply is not used above 482 °C.
     */
    public function coefficientY(string $materialFamily, float $temperatureC): ?float
    {
        $cy = $this->pack()['coefficientY'];
        $temps = $cy['temperaturesC'];
        $family = strtoupper(trim($materialFamily));

        $row = null;
        foreach ($cy['rows'] as $r) {
            if ($r['material'] === $family) {
                $row = $r['values'];
                break;
            }
        }
        if ($row === null) {
            return null;
        }

        // "and below" / "and above" are bands, so clamp rather than refuse.
        if ($temperatureC <= $temps[0]) {
            return $row[0] === null ? null : (float) $row[0];
        }
        $last = count($temps) - 1;
        if ($temperatureC >= $temps[$last]) {
            return $row[$last] === null ? null : (float) $row[$last];
        }

        for ($i = 1; $i <= $last; $i++) {
            if ($temperatureC > $temps[$i]) {
                continue;
            }
            $lo = $row[$i - 1];
            $hi = $row[$i];
            if ($lo === null || $hi === null) {
                return null;
            }
            $span = $temps[$i] - $temps[$i - 1];
            $f = ($temperatureC - $temps[$i - 1]) / $span;

            return (float) ($lo + ($hi - $lo) * $f);
        }

        return null;
    }

    /**
     * Pressure design thickness t and minimum required thickness tm, in mm.
     *
     * Every input is named because every one of them can be wrong quietly.
     * Returns a `refusal` instead of figures whenever the Code does not
     * authorise the calculation.
     *
     * @param  float  $designPressureKpa  P, internal design GAUGE pressure
     * @param  float  $outsideDiameterMm  D
     * @param  float|null  $allowableStressKpa  S from Table A-1 — the caller's
     *                                          to supply and to cite
     * @param  float  $qualityFactorE  E, from Table A-1A or A-1B
     * @param  float  $weldStrengthReductionW  W, para. 302.3.5(e)
     * @param  float  $allowancesMm  c — corrosion, erosion and mechanical
     * @return array{t:float|null,tm:float|null,Y:float|null,refusal:string|null,
     *               governedBy:string|null}
     */
    public function calculate(
        float $designPressureKpa,
        float $outsideDiameterMm,
        ?float $allowableStressKpa,
        string $materialFamily,
        float $designTemperatureC,
        float $allowancesMm = 0.0,
        float $qualityFactorE = 1.0,
        float $weldStrengthReductionW = 1.0,
    ): array {
        $none = ['t' => null, 'tm' => null, 'Y' => null, 'governedBy' => null];

        if ($allowableStressKpa === null) {
            return $none + ['refusal' => 'ALLOWABLE_STRESS_NOT_SUPPLIED'];
        }
        if ($allowableStressKpa <= 0.0 || $outsideDiameterMm <= 0.0) {
            return $none + ['refusal' => 'INPUT_NOT_POSITIVE'];
        }

        $y = $this->coefficientY($materialFamily, $designTemperatureC);
        if ($y === null) {
            return $none + ['refusal' => 'COEFFICIENT_Y_NOT_TABULATED'];
        }

        $se = $allowableStressKpa * $qualityFactorE;
        if ($se <= 0.0) {
            return $none + ['refusal' => 'INPUT_NOT_POSITIVE'];
        }

        // Clause 304.1.2(b), first limit. Checked BEFORE dividing, because
        // past it the denominator is not the point - the Code is.
        $limit = (float) $this->pack()['wallThickness']['validity']['pOverSeLimit'];
        if ($designPressureKpa / $se > $limit) {
            return $none + ['refusal' => 'P_OVER_SE_ABOVE_0_385', 'Y' => $y];
        }

        $denominator = 2 * ($se * $weldStrengthReductionW + $designPressureKpa * $y);
        if ($denominator <= 0.0) {
            return $none + ['refusal' => 'INPUT_NOT_POSITIVE', 'Y' => $y];
        }

        $t = $designPressureKpa * $outsideDiameterMm / $denominator;

        // Clause 304.1.2(b), second limit. Y itself changes form above it, so
        // the tabulated value no longer applies and neither does eq. (3a).
        if ($t >= $outsideDiameterMm / 6) {
            return $none + ['refusal' => 'THICK_WALL_T_AT_OR_ABOVE_D_OVER_6', 'Y' => $y];
        }

        return [
            't' => $t,
            'tm' => $t + $allowancesMm,
            'Y' => $y,
            'refusal' => null,
            'governedBy' => 'B31.3 304.1.2 eq. (3a)',
        ];
    }

    /** Plain-language reason for a refusal, for a finding message. */
    public function explain(string $refusal): string
    {
        return match ($refusal) {
            'ALLOWABLE_STRESS_NOT_SUPPLIED' =>
                'No allowable stress. B31.3 Table A-1 is not transcribed yet, so S has '
                .'to come from the caller with a citation - a guessed S gives a wrong '
                .'thickness that looks right.',
            'COEFFICIENT_Y_NOT_TABULATED' =>
                'Table 304.1.1-1 gives no Y for that material at that temperature. Gray '
                .'iron, for one, is simply not used above 482 C.',
            'P_OVER_SE_ABOVE_0_385' =>
                'P/SE is above 0.385, so clause 304.1.2(b) withdraws the thin-wall '
                .'equation and calls for special consideration of failure theory, '
                .'fatigue and thermal stress.',
            'THICK_WALL_T_AT_OR_ABOVE_D_OVER_6' =>
                'The calculated t reaches D/6, where equations (3a) and (3b) stop '
                .'applying and Y itself changes form. This is a thick-wall problem, not '
                .'a thin-wall one.',
            'INPUT_NOT_POSITIVE' =>
                'A pressure, diameter or stress was zero or negative.',
            default => $refusal,
        };
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['wallThickness']['clause'].' of '.$p['standard']['code'].')';
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
