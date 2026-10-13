<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * What one flanged joint needs, and whether a take-off covers it.
 *
 * WHY THIS IS A CHECK AND NOT A CALCULATION
 * -----------------------------------------
 * Every other tool in this space - Plant 3D, ISOGEN - generates the bolt set
 * from the flanged joint and puts it on the BOM automatically. We deliberately
 * do not. On a real project bolting is ordered with an allowance: studs are
 * dropped, galled, cut wrong, and a job that orders exactly the calculated
 * count stops for a box of nuts. So BOLT and NUT are take-off lines in their
 * own right, entered by the material engineer, and this class only answers the
 * question that matters: IS IT ENOUGH? A surplus is never an error here. A
 * shortfall always is.
 *
 * WHAT "ENOUGH" MEANS
 * -------------------
 * Four separate things, and a take-off can fail any one while passing the rest:
 *
 *   1. COUNT      - at least boltCount bolts per joint, summed over the joints.
 *   2. NUTS       - at least two nuts per stud bolt (one per end), or one per
 *                   machine bolt. The caller names the STYLE and the ratio
 *                   follows; it cannot pass a ratio directly, because a caller
 *                   able to say "one nut per bolt" on a studded joint halves
 *                   the nut order with nothing to catch it.
 *   3. DIAMETER   - a 3/4 in bolt does not enter a 7/8 in bolt hole's
 *                   neighbour. Too SMALL is a shortfall; too large will not
 *                   fit the hole at all, which is a different error and is
 *                   reported as such rather than being quietly accepted.
 *   4. LENGTH     - a stud must cross two flanges, the gasket and two nuts.
 *                   Shorter than the reference minimum is a shortfall. Longer
 *                   is normal practice and is not flagged. A machine bolt is
 *                   measured from UNDER THE HEAD and carries one nut, so it is
 *                   always shorter for the same joint and is checked against
 *                   its own column - roughly 20 mm apart, which is enough to
 *                   raise a false shortfall if the wrong column is used.
 *
 * AND ONE THING THAT IS NOT A SHORTFALL AT ALL
 * --------------------------------------------
 * B16.5 permits machine bolts only at Class 150 and 300. Above that the joint
 * must be studded. That is reported as a MISFIT, not a shortfall: nothing is
 * missing, the wrong fastener has been specified, and ordering more of it does
 * not help.
 *
 * WHY A JOINT IS TWO FLANGES
 * --------------------------
 * A flanged joint is two flanges and ONE set of bolts. An MTO that counts a
 * bolt set per flange orders double. This class therefore takes JOINTS, and
 * makes the caller state how flange lines became joint counts - see
 * `jointsFromFlangePairs()`, which refuses an odd number rather than rounding.
 *
 * NOTHING HERE IS VERIFIED
 * ------------------------
 * The reference lengths carry verified=false, exactly as the material library
 * does. `shortfalls()` reports a length finding as ADVISORY for that reason:
 * the count and the diameter are hard facts of B16.5, the length depends on
 * facing and gasket thickness and must be confirmed against a purchased copy
 * before anyone is told their order is short.
 */
final class FlangeBolting
{
    private const REF_ID = 'flange-bolting';

    /**
     * The fastener style. Everything downstream follows from it: how many nuts
     * a bolt takes, which length column applies, and whether the class even
     * permits it. It is a style, never a bare ratio, because a caller that can
     * pass `1` for a stud can silently halve a nut order.
     */
    public const STYLE_STUD = 'STUD';

    public const STYLE_MACHINE = 'MACHINE';

    /** A stud takes a nut at each end; a machine bolt has a head and takes one. */
    public const NUTS_PER_STUD = 2;

    public const NUTS_PER_MACHINE_BOLT = 1;

    /** B16.5 permits machine bolts at these classes only; above, studs. */
    public const MACHINE_BOLT_CLASSES = ['150#', '300#'];

    public const SEVERITY_SHORTFALL = 'SHORTFALL';

    public const SEVERITY_MISFIT = 'MISFIT';

    public const SEVERITY_ADVISORY = 'ADVISORY';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /** @return list<string> Ratings the pack can answer for, in table order. */
    public function ratings(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['rating'],
            $this->pack()['ratings'],
        );
    }

    /**
     * What ONE joint of this rating and size needs.
     *
     * Returns null when the pack cannot answer - an unlisted size or rating.
     * Null is not "no bolts required"; the caller must not treat it as zero,
     * which is why `shortfalls()` reports it as an explicit finding instead.
     *
     * @return array{boltCount:int,boltDiameterIn:string,boltDiameterDecimal:float,minLengthMm:int,minLengthMachineBoltMm:int|null,minLengthRingJointMm:int|null,minLengthTongueGrooveMm:int|null,boltCircleMm:float|null,flangeOdMm:int|null,verified:bool,deferredFrom:string|null,ratingUsed:string}|null
     */
    /**
     * Is this size and class inside what the pack claims to cover?
     *
     * The reason this exists: forJoint() used to return null both for "this
     * joint needs no checking" and for "this pack has never heard of NPS 30",
     * and every caller read null as the first. Nothing consumed it yet, so
     * nothing broke - but a bolting check that goes quiet above NPS 24 is a
     * check that passes every large flange on a project. ADR-008 section 11.14.
     *
     * Callers must ask this BEFORE treating a null from forJoint() as "nothing
     * to check". Where this returns false the honest report is UNKNOWN.
     */
    public function covers(string $standard, ?string $series, string $nps): bool
    {
        $wanted = self::inchesToDecimal($nps);

        foreach ($this->pack()['coverage'] as $c) {
            if ($c['standard'] !== $standard || ($c['series'] ?? null) !== $series) {
                continue;
            }
            if ($c['hasRows'] !== true) {
                // Declared so that a caller can name the gap, but deliberately
                // empty. Still unknown, and saying so is the entire point.
                continue;
            }

            if ($wanted >= self::inchesToDecimal($c['npsFrom'])
                && $wanted <= self::inchesToDecimal($c['npsTo'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the pack says about its own scope, for a screen that must explain
     * why a large flange produced no finding.
     *
     * @return list<array{standard:string,editionYear:int,series:string|null,npsFrom:string,npsTo:string,hasRows:bool,note:string}>
     */
    public function coverage(): array
    {
        return $this->pack()['coverage'];
    }

    /**
     * @param  string|null  $series  B16.47 Series A or B. Required whenever the
     *                               size exists in both, because they are
     *                               different flanges: a 30 inch Series A has a
     *                               different bolt circle from a Series B. Null
     *                               is fine below NPS 26, where only B16.5 answers.
     */
    public function forJoint(
        string $rating,
        string $nps,
        ?string $series = null,
        int $hops = 0,
    ): ?array {
        $hits = [];

        foreach ($this->pack()['ratings'] as $entry) {
            if ($entry['rating'] !== $rating) {
                continue;
            }
            if ($series !== null && ($entry['series'] ?? null) !== $series) {
                continue;
            }
            foreach ($entry['bolting'] as $row) {
                if ($row['nps'] !== $nps) {
                    continue;
                }

                // Class 400 below NPS 4 and Class 900 below NPS 3 are drilled
                // to another class entirely - B16.5 prints "use Class 600 /
                // Class 1500 dimensions in this size" instead of numbers. The
                // answer is that other class's row, and the caller is told
                // where it came from so a take-off can be explained. The hop
                // limit is belt and braces: the pack's own validator refuses a
                // deferral that does not land on a dimensioned row.
                if (isset($row['deferredToClass'])) {
                    if ($hops > 0) {
                        return null;
                    }
                    $target = $row['deferredToClass'].'#';
                    $resolved = $this->forJoint($target, $nps, $series, $hops + 1);

                    // array_merge, not '+': the resolved row already carries
                    // deferredFrom => null and '+' keeps the existing key.
                    if ($resolved === null) {
                        return null;
                    }
                    $hits[] = array_merge(
                        $resolved,
                        ['deferredFrom' => $rating, 'ratingUsed' => $target],
                    );

                    continue;
                }

                // The standard declines this size at this class and series.
                // That is an answer, and it is not "no bolts needed".
                if (! empty($row['notOffered'])) {
                    continue;
                }

                $hits[] = [
                    'boltCount' => (int) $row['boltCount'],
                    'boltDiameterIn' => (string) $row['boltDiameterIn'],
                    'boltDiameterDecimal' => self::inchesToDecimal((string) $row['boltDiameterIn']),
                    'minLengthMm' => (int) $row['minLengthMm'],
                    // Absent above 300# because the standard forbids the style
                    // there, not because the figure is unknown.
                    'minLengthMachineBoltMm' => isset($row['minLengthMachineBoltMm'])
                        ? (int) $row['minLengthMachineBoltMm']
                        : null,
                    // Three facings, three stud lengths. A ring joint and a
                    // tongue-and-groove joint are not the raised-face figure,
                    // and ordering the raised-face length for a ring joint
                    // buys a bolt that does not reach.
                    'minLengthRingJointMm' => isset($row['minLengthRingJointMm'])
                        ? (int) $row['minLengthRingJointMm']
                        : null,
                    'minLengthTongueGrooveMm' => isset($row['minLengthTongueGrooveMm'])
                        ? (int) $row['minLengthTongueGrooveMm']
                        : null,
                    'boltCircleMm' => isset($row['boltCircleMm']) ? (float) $row['boltCircleMm'] : null,
                    'flangeOdMm' => isset($row['flangeOdMm']) ? (int) $row['flangeOdMm'] : null,
                    'verified' => (bool) $row['verified'],
                    'deferredFrom' => null,
                    'ratingUsed' => $rating,
                    'standard' => $entry['standard'],
                    'series' => $entry['series'] ?? null,
                ];
            }
        }

        // Above NPS 24 the same rating and size exist in both B16.47 series
        // with different drilling. Picking one would be a guess, and a guess
        // here orders the wrong bolts, so the caller is told nothing instead
        // and must state the series.
        if (count($hits) > 1) {
            return null;
        }

        return $hits[0] ?? null;
    }

    /**
     * Which B16.47 series answer this rating and size. Empty below NPS 26.
     * A screen uses this to explain why forJoint() declined.
     *
     * @return list<string>
     */
    public function seriesAnswering(string $rating, string $nps): array
    {
        $out = [];
        foreach ($this->pack()['ratings'] as $entry) {
            if ($entry['rating'] !== $rating || ($entry['series'] ?? null) === null) {
                continue;
            }
            foreach ($entry['bolting'] as $row) {
                if ($row['nps'] === $nps && empty($row['notOffered'])) {
                    $out[] = $entry['series'];
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Two flanges make one joint. An odd count is a modelling error, not a
     * half joint, so it is refused rather than rounded in either direction.
     *
     * A blind flange, a flanged valve or an orifice pair changes this arithmetic,
     * which is precisely why the caller states the joint count and this helper
     * is offered rather than imposed.
     */
    public static function jointsFromFlangePairs(int $flangeCount): int
    {
        if ($flangeCount < 0) {
            throw new RuntimeException('Flange count cannot be negative.');
        }
        if ($flangeCount % 2 !== 0) {
            throw new RuntimeException(
                "Cannot pair {$flangeCount} flanges into joints: an odd flange "
                .'count means a blind, a valve or an equipment nozzle is '
                .'unaccounted for. State the joint count explicitly.'
            );
        }

        return intdiv($flangeCount, 2);
    }

    /**
     * The whole point of this class.
     *
     * Given what the flanges in the MTO require and what the bolting lines in
     * the same MTO actually supply, list everything that is NOT ENOUGH. An
     * empty list means the take-off covers the work. A surplus never appears.
     *
     * @param  list<array{rating:string,nps:string,joints:int}>  $required
     *                                     one entry per rating/size combination the MTO's flanges imply
     * @param  array{bolts:int,nuts:int,boltDiameterIn?:string|null,boltLengthMm?:int|null}  $supplied
     *                                     what the BOLT and NUT lines of the MTO carry
     * @param  string  $style  STYLE_STUD or STYLE_MACHINE. The nut ratio and the
     *                          length column both follow from it - see the
     *                          constants. Passing a bare ratio was possible in
     *                          the first cut of this class and let a caller
     *                          declare a stud joint and then count one nut per
     *                          bolt, halving the nut order with nothing to
     *                          catch it.
     * @return list<array{code:string,severity:string,rating:string|null,nps:string|null,required:int|string,supplied:int|string,message:string}>
     */
    public function shortfalls(array $required, array $supplied, string $style = self::STYLE_STUD): array
    {
        if (! in_array($style, [self::STYLE_STUD, self::STYLE_MACHINE], true)) {
            throw new RuntimeException(
                "Unknown fastener style '{$style}'. A joint is studded or bolted; "
                .'there is no third case.'
            );
        }

        $machine = $style === self::STYLE_MACHINE;
        $nutsPerBolt = $machine ? self::NUTS_PER_MACHINE_BOLT : self::NUTS_PER_STUD;

        $findings = [];
        $neededBolts = 0;
        $widestDecimal = 0.0;
        $widestLabel = null;
        $longestMm = 0;
        $lengthUnverified = false;

        foreach ($required as $line) {
            $rating = (string) $line['rating'];
            $nps = (string) $line['nps'];
            $joints = (int) $line['joints'];

            if ($joints <= 0) {
                continue;
            }

            $ref = $this->forJoint($rating, $nps);
            if ($ref === null) {
                // Silence here would let an unlisted size pass as satisfied.
                $findings[] = [
                    'code' => 'UNKNOWN_JOINT',
                    'severity' => self::SEVERITY_SHORTFALL,
                    'rating' => $rating,
                    'nps' => $nps,
                    'required' => '?',
                    'supplied' => $supplied['bolts'],
                    'message' => "No B16.5 bolting is held for {$nps} {$rating}; "
                        .'the requirement cannot be checked and must not be assumed to be met.',
                ];

                continue;
            }

            $neededBolts += $ref['boltCount'] * $joints;

            // A machine bolt is not merely shorter at Class 600 - it is not
            // allowed. Reporting this as a shortfall would be wrong: nothing is
            // missing, the wrong fastener has been specified.
            if ($machine && ! in_array($rating, self::MACHINE_BOLT_CLASSES, true)) {
                $findings[] = [
                    'code' => 'STYLE_NOT_PERMITTED',
                    'severity' => self::SEVERITY_MISFIT,
                    'rating' => $rating,
                    'nps' => $nps,
                    'required' => self::STYLE_STUD,
                    'supplied' => self::STYLE_MACHINE,
                    'message' => "B16.5 allows machine bolts only at "
                        .implode(' and ', self::MACHINE_BOLT_CLASSES)
                        .", so a {$nps} {$rating} joint must be studded.",
                ];
            }

            if ($ref['boltDiameterDecimal'] > $widestDecimal) {
                $widestDecimal = $ref['boltDiameterDecimal'];
                $widestLabel = $ref['boltDiameterIn'];
            }
            // The two lengths are measured from different places - a stud
            // thread-to-thread, a machine bolt from under the head - so they
            // are never compared with each other, only each with its own kind.
            $refLength = $machine
                ? ($ref['minLengthMachineBoltMm'] ?? $ref['minLengthMm'])
                : $ref['minLengthMm'];

            if ($refLength > $longestMm) {
                $longestMm = $refLength;
                $lengthUnverified = ! $ref['verified'];
            }
        }

        // 1. Bolt count.
        if ($supplied['bolts'] < $neededBolts) {
            $findings[] = [
                'code' => 'BOLT_COUNT',
                'severity' => self::SEVERITY_SHORTFALL,
                'rating' => null,
                'nps' => null,
                'required' => $neededBolts,
                'supplied' => (int) $supplied['bolts'],
                'message' => "The flanges need {$neededBolts} bolts; the take-off "
                    ."carries {$supplied['bolts']}.",
            ];
        }

        // 2. Nuts. Counted against the bolts actually supplied when that is the
        //    larger number, because the extra bolts still need nuts - ordering
        //    a surplus of studs and an exact count of nuts is a common slip.
        $neededNuts = max($neededBolts, (int) $supplied['bolts']) * $nutsPerBolt;
        if ($supplied['nuts'] < $neededNuts) {
            $findings[] = [
                'code' => 'NUT_COUNT',
                'severity' => self::SEVERITY_SHORTFALL,
                'rating' => null,
                'nps' => null,
                'required' => $neededNuts,
                'supplied' => (int) $supplied['nuts'],
                'message' => "At {$nutsPerBolt} nuts per bolt the take-off needs "
                    ."{$neededNuts} nuts; it carries {$supplied['nuts']}.",
            ];
        }

        // 3. Diameter. One bolting line cannot serve a joint that needs a
        //    fatter bolt, and a bolt fatter than the hole does not go in.
        $suppliedDia = $supplied['boltDiameterIn'] ?? null;
        if ($suppliedDia !== null && $widestLabel !== null) {
            $have = self::inchesToDecimal($suppliedDia);
            if ($have < $widestDecimal) {
                $findings[] = [
                    'code' => 'BOLT_DIAMETER',
                    'severity' => self::SEVERITY_SHORTFALL,
                    'rating' => null,
                    'nps' => null,
                    'required' => $widestLabel,
                    'supplied' => $suppliedDia,
                    'message' => "The largest joint needs a {$widestLabel} in bolt; "
                        ."the take-off carries {$suppliedDia} in.",
                ];
            } elseif ($have > $widestDecimal) {
                $findings[] = [
                    'code' => 'BOLT_DIAMETER',
                    'severity' => self::SEVERITY_MISFIT,
                    'rating' => null,
                    'nps' => null,
                    'required' => $widestLabel,
                    'supplied' => $suppliedDia,
                    'message' => "A {$suppliedDia} in bolt will not enter a bolt hole "
                        ."drilled for {$widestLabel} in. Surplus quantity is fine; "
                        .'surplus diameter is not.',
                ];
            }
        }

        // 4. Length - advisory, because the reference figure is unverified and
        //    depends on facing and gasket thickness.
        $suppliedLen = $supplied['boltLengthMm'] ?? null;
        if ($suppliedLen !== null && $longestMm > 0 && $suppliedLen < $longestMm) {
            $findings[] = [
                'code' => 'BOLT_LENGTH',
                'severity' => $lengthUnverified ? self::SEVERITY_ADVISORY : self::SEVERITY_SHORTFALL,
                'rating' => null,
                'nps' => null,
                'required' => $longestMm,
                'supplied' => (int) $suppliedLen,
                'message' => "The longest joint wants at least {$longestMm} mm; the "
                    ."take-off carries {$suppliedLen} mm."
                    .($lengthUnverified
                        ? ' This figure has not been checked against a purchased copy '
                          .'of B16.5 and depends on facing and gasket thickness, so it '
                          .'is advisory.'
                        : ''),
            ];
        }

        return $findings;
    }

    /** True when nothing is short. Surplus is not a failure. */
    public function isSufficient(array $required, array $supplied, string $style = self::STYLE_STUD): bool
    {
        foreach ($this->shortfalls($required, $supplied, $style) as $finding) {
            if ($finding['severity'] !== self::SEVERITY_ADVISORY) {
                return false;
            }
        }

        return true;
    }

    /** '1 1/8' -> 1.125. Bolt diameters are written the way a fitter says them. */
    public static function inchesToDecimal(string $text): float
    {
        $total = 0.0;
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '/')) {
                [$num, $den] = explode('/', $part, 2);
                if ((float) $den == 0.0) {
                    throw new RuntimeException("Not a bolt diameter: {$text}");
                }
                $total += (float) $num / (float) $den;

                continue;
            }
            $total += (float) $part;
        }

        return $total;
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/'.self::REF_ID.'.ref.json';
        if (! is_file($path)) {
            throw new RuntimeException('Flange bolting pack not found (looked in '.$path.')');
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->pack = $decoded;
    }
}
