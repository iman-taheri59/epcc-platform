<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/**
 * Translation between PCF's component vocabulary and our 20 templates.
 *
 * WHY A TABLE AND NOT A GUESS. PCF component names carry their geometry in the
 * name: `ELBOW`, `BEND`, `TEE-SET-ON`, `OLET`, `REDUCER-CONCENTRIC`. Most map
 * cleanly. The ones that do not are the interesting cases, and every one of
 * them is a decision that belongs here in daylight rather than in an `else`
 * branch somewhere:
 *
 *   - `TEE-SET-ON` / `TEE-SET-IN` are stub-in branches, geometrically an OLET
 *     rather than a fitting tee. They are mapped to OLET, because the take-off
 *     and the weld count differ - a set-on branch has ONE weld, a fitting tee
 *     has three.
 *   - `GASKET` is a component here as it is there, even though some MTO
 *     practice bundles it with the flange. Keeping it separate is what lets
 *     the warehouse issue it separately, which is what actually happens.
 *   - `SUPPORT` and `PIPE-BLOCK` are not pressure-containing parts. They are
 *     recognised so they can be counted and reported, and deliberately NOT
 *     given a template: supports are phase 5 and inventing a component for
 *     them now would put objects in the model that the joint engine would then
 *     have to special-case.
 *
 * Anything absent from this table is reported as unmapped. That is the whole
 * point of the class: an unknown component must be visible, never silently a
 * PIPE.
 */
final class PcfVocabulary
{
    /**
     * PCF component type => our template code.
     *
     * @var array<string,string>
     */
    private const MAP = [
        'PIPE' => 'PIPE',
        'PIPE-FIXED' => 'PIPE',
        'TUBE' => 'PIPE',

        'ELBOW' => 'ELBOW',
        'BEND' => 'ELBOW',
        'ELBOW-45' => 'ELBOW',
        'MITRE-BEND' => 'ELBOW',

        'TEE' => 'TEE',
        'TEE-SKEWED' => 'TEE',
        'CROSS' => 'CROSS',

        // Stub-in branches: one weld, no fitting. See the class docblock.
        'TEE-SET-ON' => 'OLET',
        'TEE-SET-IN' => 'OLET',
        'OLET' => 'OLET',
        'WELDOLET' => 'OLET',
        'SOCKOLET' => 'OLET',
        'THREDOLET' => 'OLET',

        'REDUCER-CONCENTRIC' => 'REDUCER',
        'REDUCER-ECCENTRIC' => 'REDUCER',
        'REDUCER' => 'REDUCER',
        'SWAGE' => 'SWAGE',

        'FLANGE' => 'FLANGE',
        'FLANGE-BLIND' => 'BLIND',
        'FLANGE-SPECTACLE' => 'SPECTACLE_BLIND',
        'GASKET' => 'GASKET',

        'VALVE' => 'VALVE',
        'VALVE-ANGLE' => 'VALVE',
        'VALVE-3WAY' => 'THREE_WAY_VALVE',
        'VALVE-4WAY' => 'THREE_WAY_VALVE',
        'CONTROL-VALVE' => 'CONTROL_VALVE',
        'VALVE-CONTROL' => 'CONTROL_VALVE',

        'INSTRUMENT' => 'INSTRUMENT',
        'INSTRUMENT-DIAL' => 'INSTRUMENT',
        'INSTRUMENT-2COMP' => 'INSTRUMENT',
        'INSTRUMENT-3COMP' => 'INSTRUMENT',

        'CAP' => 'CAP',
        'UNION' => 'UNION',
        'COUPLING' => 'COUPLING',
        'HALF-COUPLING' => 'COUPLING',
        'NIPPLE' => 'NIPPLE',

        'NOZZLE' => 'NOZZLE',
    ];

    /**
     * Recognised but deliberately not modelled as components.
     *
     * @var list<string>
     */
    private const NOT_MODELLED = [
        'SUPPORT', 'PIPE-BLOCK', 'STRUCTURAL', 'HANGER', 'SHOE',
        'MISC-COMPONENT', 'BOLT', 'WELD', 'INSULATION',
    ];

    public static function templateFor(string $pcfType): ?string
    {
        return self::MAP[strtoupper(trim($pcfType))] ?? null;
    }

    public static function isNotModelled(string $pcfType): bool
    {
        return in_array(strtoupper(trim($pcfType)), self::NOT_MODELLED, true);
    }

    /**
     * The end type, preferring the keypoint's own code over the SKEY's.
     *
     * A SKEY is four characters and its LAST TWO are the end connection:
     * `EL` + `BW` = butt-welded elbow, `GTFL` = flanged gate valve. That makes
     * the SKEY a usable fallback when a keypoint omits its end type, which
     * exporters do for components whose ends are uniform.
     *
     * Only codes in our 13-strong vocabulary are returned; `**` (the PCF
     * wildcard meaning "any end") is treated as unknown, because storing a
     * wildcard as if it were a real end type would let the compatibility
     * engine pass a pair it has no evidence about.
     */
    public static function endTypeFrom(?string $keypointEnd, ?string $skey, array $known): ?string
    {
        foreach ([$keypointEnd, self::endFromSkey($skey)] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $code = strtoupper(trim($candidate));

            if ($code === '**' || $code === '') {
                continue;
            }

            if (in_array($code, $known, true)) {
                return $code;
            }
        }

        return null;
    }

    private static function endFromSkey(?string $skey): ?string
    {
        $skey = strtoupper(trim((string) $skey));

        return strlen($skey) >= 4 ? substr($skey, -2) : null;
    }
}
