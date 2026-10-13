<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * The material library: group -> spec -> grade -> product form.
 *
 * Reference data, not project data. It follows the same rules as
 * PipeReference: a file on disk, decoded once per process, with no writer
 * anywhere in the codebase (ADR-007 §4).
 *
 * WHAT AN MTO ROW STORES
 * ----------------------
 * The four STRINGS - group, spec, grade, product form - never an array index
 * or a row id. The pack can then be reordered, extended or re-sorted without
 * touching a single project row. `describe()` turns those four strings back
 * into a human label; `isKnown()` says whether they still resolve.
 *
 * WHY THE PRODUCT FORM IS A SEPARATE CHOICE
 * -----------------------------------------
 * One specification can be supplied in several forms, and the form - not the
 * grade - carries the weld joint quality factor Ew used in the B31.3 wall
 * thickness calculation: seamless 1.00, ERW 0.85, EFW without radiography
 * 0.80, EFW fully radiographed 1.00, furnace butt welded 0.60. ASTM A53 is the
 * clearest case: Type S, E and F are the same grade in three forms with three
 * different factors. Collapsing the form into the grade would silently pick
 * one factor for all of them.
 *
 * A GRADE IS IDENTIFIED BY CODE **AND** CLASS
 * -------------------------------------------
 * ASTM A358 grade 304 exists as Class 1 (fully radiographed) and Class 3 (not
 * radiographed). Same code, different acceptance and a different Ew. Every
 * lookup here is keyed on the PAIR, and `gradeKey()` is the one place that
 * knows how to spell it.
 *
 * METALLIC AND NON-METALLIC ARE DESIGNED BY DIFFERENT ROUTES
 * ----------------------------------------------------------
 * `isMetallic()` gates the whole S x E x W world. GRE and PE carry no
 * allowable stress and no weld joint quality factor at all - B31.3 Chapter VII
 * rates them from a qualified pressure instead - so their product forms return
 * null, and `isKnownProductForm()` exists to tell that null apart from a typo.
 *
 * NOTHING HERE IS VERIFIED
 * ------------------------
 * Every property carries verified=false until a human checks it against a
 * purchased copy of the standard. `verifiedProperties()` returns only the
 * checked ones, so a calculation can refuse to run on hearsay. Allowable
 * stress values are deliberately absent from the pack entirely.
 */
final class MaterialLibrary
{
    private const REF_ID = 'materials';

    /** The four-step material cascade: group -> spec -> grade -> product form. */
    public const REACHED_BY_CASCADE = 'CASCADE';

    /** Never chosen directly; only ever named by another grade's constituent. */
    public const REACHED_BY_CONSTITUENT = 'CONSTITUENT';

    /** A separate selector ALONGSIDE the body material, not instead of it. */
    public const REACHED_BY_TRIM = 'TRIM';

    /** Nameable on a requisition, but supplies no component type. */
    public const REACHED_BY_REQUISITION_ONLY = 'REQUISITION_ONLY';

    /**
     * The parts a trim names are NOT universal - they come from the trim's own
     * standard. API 600 covers bolted bonnet GATE valves only: a ball valve has
     * no backseat and a check valve has no stem. `requiredParts` on each spec
     * is the authority; this list is only the union, for iterating properties.
     */
    public const TRIM_PARTS = ['seat', 'disc', 'backseat', 'stem', 'ball', 'plug', 'hingePin'];

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    // ------------------------------------------------------------ cascade --

    /**
     * Step 1 of the cascade: the groups that can supply a component type.
     *
     * A flange comes from forgings, not from pipe. Filtering here is what
     * stops the second dropdown offering every group in the library.
     *
     * Only CASCADE groups can appear here at all: a group reached any other
     * way has no component types by construction, and the validator enforces
     * that both ways round. The guard is restated rather than implied, because
     * the failure it prevents is silent - VALVE_TRIM offering itself where a
     * body material belongs would leave the valve with no body.
     *
     * @return list<array{code:string,name:array{en:string,fa:string},metallic:bool,reachedBy:string}>
     */
    public function groupsForComponentType(string $componentType): array
    {
        $out = [];
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_CASCADE) {
                continue;
            }
            if (in_array($componentType, $group['componentTypes'], true)) {
                $out[] = [
                    'code' => $group['code'],
                    'name' => $group['name'],
                    'metallic' => (bool) $group['metallic'],
                    'reachedBy' => $group['reachedBy'],
                ];
            }
        }

        return $out;
    }

    /** @return list<array{code:string,name:array{en:string,fa:string},metallic:bool}> */
    public function groups(): array
    {
        return array_map(
            static fn (array $g): array => [
                'code' => $g['code'],
                'name' => $g['name'],
                'metallic' => (bool) $g['metallic'],
                'reachedBy' => $g['reachedBy'],
            ],
            $this->pack()['groups'],
        );
    }

    /**
     * Step 2: the specifications inside a group.
     *
     * @return list<array{code:string,designation:string,title:array{en:string,fa:string},hasGrades:bool}>
     */
    public function specsIn(string $groupCode): array
    {
        $group = $this->findGroup($groupCode);
        if ($group === null) {
            return [];
        }

        return array_map(static fn (array $s): array => [
            'code' => $s['code'],
            'designation' => $s['designation'],
            'title' => $s['title'],
            'hasGrades' => $s['grades'] !== [],
        ], $group['specs']);
    }

    /**
     * Step 3: the grades of a specification.
     *
     * MAY LEGITIMATELY BE EMPTY - ASTM A105 has no grades. The caller must
     * disable the grade control, not render an empty dropdown. `specHasGrades()`
     * is the explicit question so a view never has to infer it from count().
     *
     * @return list<array{code:string,classOrType:string|null,key:string,name:array{en:string,fa:string}}>
     */
    public function gradesOf(string $groupCode, string $specCode): array
    {
        $spec = $this->findSpec($groupCode, $specCode);
        if ($spec === null) {
            return [];
        }

        return array_map(static fn (array $g): array => [
            'code' => $g['code'],
            'classOrType' => $g['classOrType'],
            'key' => self::gradeKey($g['code'], $g['classOrType']),
            'name' => $g['name'],
        ], $spec['grades']);
    }

    public function specHasGrades(string $groupCode, string $specCode): bool
    {
        $spec = $this->findSpec($groupCode, $specCode);

        return $spec !== null && $spec['grades'] !== [];
    }

    /**
     * Step 4: the product forms a specification may be supplied in.
     *
     * @return list<array{code:string,name:array{en:string,fa:string},weldJointQualityFactor:float,verified:bool}>
     */
    public function productFormsFor(string $groupCode, string $specCode): array
    {
        $spec = $this->findSpec($groupCode, $specCode);
        if ($spec === null) {
            return [];
        }

        $forms = $this->formIndex();
        $out = [];
        foreach ($spec['productForms'] as $code) {
            if (isset($forms[$code])) {
                $out[] = $forms[$code];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ lookups --

    /**
     * The one place that knows how a grade and its class are spelled together.
     * `TP316` stays `TP316`; `304` in class 1 becomes `304 CL 1`.
     */
    public static function gradeKey(string $code, ?string $classOrType): string
    {
        return $classOrType === null || $classOrType === ''
            ? $code
            : $code.' '.$classOrType;
    }

    /**
     * Resolve a stored MTO selection back to the pack.
     *
     * $gradeKey is what gradeKey() produced, or null for a spec with no grades.
     *
     * @return array<string,mixed>|null
     */
    public function grade(string $groupCode, string $specCode, ?string $gradeKey): ?array
    {
        $spec = $this->findSpec($groupCode, $specCode);
        if ($spec === null) {
            return null;
        }

        if ($gradeKey === null || $gradeKey === '') {
            // Legitimate only where the spec genuinely has no grades.
            return $spec['grades'] === [] ? [] : null;
        }

        foreach ($spec['grades'] as $g) {
            if (self::gradeKey($g['code'], $g['classOrType']) === $gradeKey) {
                return $g;
            }
        }

        return null;
    }

    /**
     * The ASME B16.5 material group for a selection, e.g. '1.1' or '2.1'.
     *
     * This is the first hop of the pressure-temperature lookup (ADR-008 sec 11,
     * finding 2): grade -> group, then (group x class x temperature) -> pressure.
     *
     * Resolution order is grade, then specification. The fallback is not a
     * convenience: ASTM A105 deliberately has no grades - the specification IS
     * the material - so for A105 the group can only live on the spec. Rule 31
     * in validate_packs.py guarantees the two can never both answer.
     *
     * Returns null when the pack does not know, which is the honest answer for
     * every material whose group the public sources disagree about. Callers
     * MUST treat null as "unknown" and never as a default: a wrong group does
     * not fail, it silently returns a different pressure.
     */
    public function b165MaterialGroup(
        string $groupCode,
        string $specCode,
        ?string $gradeKey,
    ): ?string {
        $mg = $this->properties($groupCode, $specCode, $gradeKey)['b165MaterialGroup'] ?? null;

        return $mg === null ? null : (string) $mg['value'];
    }

    /** Does a stored selection still resolve against the current pack? */
    public function isKnown(string $groupCode, string $specCode, ?string $gradeKey, ?string $productForm = null): bool
    {
        if ($this->grade($groupCode, $specCode, $gradeKey) === null) {
            return false;
        }

        if ($productForm === null) {
            return true;
        }

        foreach ($this->productFormsFor($groupCode, $specCode) as $f) {
            if ($f['code'] === $productForm) {
                return true;
            }
        }

        return false;
    }

    /**
     * A human label for a stored selection, e.g. "ASTM A358 304 CL 1 (EFW_RT)".
     * Returns null when the selection no longer resolves, so a caller can tell
     * "not set" from "set to something the pack no longer knows".
     */
    public function describe(string $groupCode, string $specCode, ?string $gradeKey, ?string $productForm = null): ?string
    {
        $spec = $this->findSpec($groupCode, $specCode);
        if ($spec === null || ! $this->isKnown($groupCode, $specCode, $gradeKey, $productForm)) {
            return null;
        }

        $label = $spec['designation'];
        if ($gradeKey !== null && $gradeKey !== '') {
            $label .= ' '.$gradeKey;
        }
        if ($productForm !== null && $productForm !== '') {
            $label .= ' ('.$productForm.')';
        }

        return $label;
    }

    // --------------------------------------------------------- properties --

    /**
     * The weld joint quality factor Ew for a product form.
     *
     * Returns null when the form is unknown. The caller must treat null as
     * "cannot calculate", never as 1.0 - defaulting to 1.0 would quietly
     * remove the penalty a welded seam is supposed to carry.
     */
    public function weldJointQualityFactor(string $productForm): ?float
    {
        $forms = $this->formIndex();

        return $forms[$productForm]['weldJointQualityFactor'] ?? null;
    }

    /**
     * Tell "this form has no factor" apart from "I have never heard of this
     * form". Both make weldJointQualityFactor() return null, and a caller that
     * cannot distinguish them will either reject valid GRE or accept a typo.
     */
    public function isKnownProductForm(string $productForm): bool
    {
        return isset($this->formIndex()[$productForm]);
    }

    /**
     * Is this group designed by the metallic route (S x E x W) at all?
     *
     * ASME B31.3 Chapter VII designs non-metallic piping from a qualified
     * pressure rating instead. Route on THIS before reaching for a factor or
     * an allowable stress; there is neither.
     */
    public function isMetallic(string $groupCode): bool
    {
        $group = $this->findGroup($groupCode);

        return $group !== null && $group['metallic'] === true;
    }

    /**
     * The surface conditions a specification is supplied in.
     *
     * Orthogonal to the product form: A53 Type S, E and F can each be black or
     * hot-dip galvanised. Offered as a choice only where a spec declares more
     * than one.
     *
     * @return list<string>
     */
    public function surfaceConditionsFor(string $groupCode, string $specCode): array
    {
        $spec = $this->findSpec($groupCode, $specCode);

        return $spec === null ? [] : $spec['surfaceConditions'];
    }

    /**
     * Only the properties a human has checked against the standard.
     *
     * Everything in the pack ships verified=false, so today this is always
     * empty by design. A calculation should read THIS, not properties(), so
     * that it refuses to run rather than quoting an unchecked number.
     *
     * @return array<string,array<string,mixed>>
     */
    public function verifiedProperties(string $groupCode, string $specCode, ?string $gradeKey): array
    {
        return array_filter(
            $this->properties($groupCode, $specCode, $gradeKey),
            static fn (array $p): bool => ($p['verified'] ?? false) === true,
        );
    }

    /**
     * Every property, checked or not. Safe to display, NOT safe to calculate
     * with - see verifiedProperties().
     *
     * @return array<string,array<string,mixed>>
     */
    public function properties(string $groupCode, string $specCode, ?string $gradeKey): array
    {
        $grade = $this->grade($groupCode, $specCode, $gradeKey);
        if ($grade === null) {
            return []; // the selection does not resolve; it has no properties
        }

        // A gradeless specification IS the material - ASTM A105 has nowhere
        // else to put a fact - so its spec-level properties belong to the
        // selection too. Rule 32 in validate_packs.py guarantees that only a
        // gradeless spec carries any, so the grade can never be overruled;
        // the order is written grade-first anyway so the intent is on the page.
        $spec = $this->findSpec($groupCode, $specCode);

        return ($grade['properties'] ?? []) + ($spec['properties'] ?? []);
    }

    /**
     * Every trim the library holds, across every valve type.
     *
     * A valve needs BOTH a body material (from the cascade, group CASTINGS)
     * and a trim. They are two independent choices, which is exactly why trim
     * is not a cascade group.
     *
     * Rarely what a screen wants: a trim is only valid for the valve type its
     * standard covers, so use `trimsFor()`.
     *
     * @return list<array{code:string,standard:string,valveTypes:list<string>,name:array{en:string,fa:string}}>
     */
    public function trims(): array
    {
        $out = [];
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_TRIM) {
                continue;
            }
            foreach ($group['specs'] as $spec) {
                foreach ($spec['grades'] as $grade) {
                    $out[] = [
                        'code' => $grade['code'],
                        'standard' => $spec['code'],
                        'valveTypes' => $spec['appliesToValveTypes'],
                        'name' => $grade['name'],
                        // A withdrawn trim is held, not deleted: a sheet
                        // transcribed from an older revision still says
                        // "Trim 1", and the platform has to recognise it in
                        // order to report that the standard dropped it.
                        'obsolete' => (bool) ($grade['obsolete'] ?? false),
                        'alternatives' => $grade['properties']['alternativeTrimNumbers']['value'] ?? null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The trims valid for ONE valve type.
     *
     * This is the guard the first version of the trim group did not have.
     * API 600 covers bolted bonnet steel GATE VALVES and nothing else, yet its
     * chart is quoted for every valve in the industry - so an unfiltered trim
     * list will happily offer 'Trim 8, Stellite seat' for a butterfly valve,
     * which has no backseat, no disc of that kind and usually an elastomer
     * seat. Ask for the valve type and get only what its standard covers.
     *
     * @return list<array{code:string,standard:string,valveTypes:list<string>,name:array{en:string,fa:string}}>
     */
    public function trimsFor(string $valveType, bool $includeObsolete = false): array
    {
        return array_values(array_filter(
            $this->trims(),
            static fn (array $t): bool => in_array($valveType, $t['valveTypes'], true)
                && ($includeObsolete || ! $t['obsolete']),
        ));
    }

    /**
     * Has this trim number been withdrawn by its own standard?
     *
     * API 600-2021 Table 8 prints "TRIM NUMBER 1 IS OBSOLETE" across the row.
     * The industry charts this library grew from still list it as an ordinary
     * choice, so until the standard was read the platform was inviting people
     * to specify a trim that no longer exists.
     */
    public function trimIsObsolete(string $standard, string $code): bool
    {
        foreach ($this->trims() as $t) {
            if ($t['standard'] === $standard && $t['code'] === $code) {
                return $t['obsolete'];
            }
        }

        return false;
    }

    /**
     * The trim numbers a manufacturer may supply instead of the one specified
     * (API 600 Table 9). Not a synonym list: the substitution runs one way.
     *
     * @return list<string>
     */
    public function trimAlternatives(string $standard, string $code): array
    {
        foreach ($this->trims() as $t) {
            if ($t['standard'] === $standard && $t['code'] === $code) {
                return $t['alternatives'] === null
                    ? []
                    : array_map(trim(...), explode('+', (string) $t['alternatives']));
            }
        }

        return [];
    }

    /**
     * Which standard governs the trim of a given valve type, or null if the
     * library does not cover it. Null is a real answer: a diaphragm or needle
     * valve has no API trim chart here.
     */
    public function trimStandardFor(string $valveType): ?string
    {
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_TRIM) {
                continue;
            }
            foreach ($group['specs'] as $spec) {
                if (in_array($valveType, $spec['appliesToValveTypes'], true)) {
                    return $spec['code'];
                }
            }
        }

        return null;
    }

    /**
     * The parts a trim of this valve type must name.
     *
     * @return list<string>
     */
    public function trimPartsFor(string $valveType): array
    {
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_TRIM) {
                continue;
            }
            foreach ($group['specs'] as $spec) {
                if (in_array($valveType, $spec['appliesToValveTypes'], true)) {
                    return $spec['requiredParts'];
                }
            }
        }

        return [];
    }

    /**
     * Resolve one trim number into its four parts.
     *
     * Each part reports the material, and EITHER the specification and grade
     * it resolves to, OR the hardfacing overlay it carries - never neither.
     * Rule 24 in the pack validator is what guarantees the 'never neither'.
     *
     * @return array<string,array{material:string,specification:string|null,grade:string|null,hardfacing:string|null}>|null
     */
    public function trim(string $trimCode, ?string $standard = null): ?array
    {
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_TRIM) {
                continue;
            }
            foreach ($group['specs'] as $spec) {
                if ($standard !== null && $spec['code'] !== $standard) {
                    continue;
                }
                foreach ($spec['grades'] as $grade) {
                    if ($grade['code'] !== $trimCode) {
                        continue;
                    }
                    $props = $grade['properties'];
                    $out = [];
                    // Order and membership come from the STANDARD, not from a
                    // constant: a ball valve's parts are seat/ball/stem, and
                    // reading them in a fixed global order would report them
                    // in an order its own chart never uses.
                    foreach ($spec['requiredParts'] as $part) {
                        if (! isset($props[$part.'Material'])) {
                            continue;
                        }
                        $out[$part] = [
                            'material' => $props[$part.'Material']['value'],
                            'specification' => $props[$part.'Specification']['value'] ?? null,
                            // Several routes to one part. API 600 Table 8 lets
                            // a seating surface be cast, forged or weld
                            // deposited, and they are alternatives - a single
                            // 'specification' would force a choice the
                            // standard leaves to the manufacturer.
                            'specifications' => $props[$part.'Specifications']['value'] ?? null,
                            'grade' => $props[$part.'Grade']['value'] ?? null,
                            'hardfacing' => $props[$part.'Hardfacing']['value'] ?? null,
                            // A soft seat resolves to a polymer, not to a spec.
                            'polymer' => $props[$part.'Polymer']['value'] ?? null,
                        ];
                    }

                    return $out;
                }
            }
        }

        return null;
    }

    /**
     * The minimum seat hardness a trim demands, in HB, or null if it has no
     * hardfaced or hardened seat.
     *
     * This is the one number a receiving inspector can actually measure on an
     * overlay, which is why rule 25 refuses a hardfaced trim without it.
     */
    public function minSeatHardness(string $trimCode, ?string $standard = null): ?int
    {
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_TRIM) {
                continue;
            }
            foreach ($group['specs'] as $spec) {
                if ($standard !== null && $spec['code'] !== $standard) {
                    continue;
                }
                foreach ($spec['grades'] as $grade) {
                    if ($grade['code'] === $trimCode) {
                        // API 600 Table 8 calls it the SEATING SURFACE - one
                        // concept covering the seat ring and the wedge face -
                        // where the industry charts said "seat". Same fact,
                        // two spellings, and the pack transcribed from the
                        // standard uses the standard's.
                        $v = $grade['properties']['minSeatHardness']['value']
                            ?? $grade['properties']['minSeatingSurfaceHardness']['value']
                            ?? null;

                        return $v === null ? null : (int) $v;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The temperature ceiling a soft-seated trim imposes, in C, or null if the
     * trim is metal seated.
     *
     * The same truth the gasket round found about fillers: the polymer gives
     * up long before the body does. An RPTFE ball seat creeps at 232 C while
     * the A216 WCB body is untroubled to far beyond it.
     */
    public function trimMaxTemperature(string $trimCode, ?string $standard = null): ?int
    {
        foreach ($this->pack()['groups'] as $group) {
            if ($group['reachedBy'] !== self::REACHED_BY_TRIM) {
                continue;
            }
            foreach ($group['specs'] as $spec) {
                if ($standard !== null && $spec['code'] !== $standard) {
                    continue;
                }
                foreach ($spec['grades'] as $grade) {
                    if ($grade['code'] === $trimCode) {
                        $v = $grade['properties']['maxTemperature']['value'] ?? null;

                        return $v === null ? null : (int) $v;
                    }
                }
            }
        }

        return null;
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    // ---------------------------------------------------------- internals --

    /** @return array<string,array{code:string,name:array{en:string,fa:string},weldJointQualityFactor:float,verified:bool}> */
    private function formIndex(): array
    {
        $out = [];
        foreach ($this->pack()['productForms'] as $f) {
            $out[$f['code']] = [
                'code' => $f['code'],
                'name' => $f['name'],
                // May legitimately be null for a non-metallic form. Casting to
                // float would turn that into 0.0 and quietly zero the pressure
                // a GRE line may hold.
                'weldJointQualityFactor' => $f['weldJointQualityFactor'] === null
                    ? null
                    : (float) $f['weldJointQualityFactor'],
                'verified' => (bool) $f['verified'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function findGroup(string $groupCode): ?array
    {
        foreach ($this->pack()['groups'] as $group) {
            if ($group['code'] === $groupCode) {
                return $group;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findSpec(string $groupCode, string $specCode): ?array
    {
        $group = $this->findGroup($groupCode);
        if ($group === null) {
            return null;
        }

        foreach ($group['specs'] as $spec) {
            if ($spec['code'] === $specCode) {
                return $spec;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/'.self::REF_ID.'.ref.json';
        if (! is_file($path)) {
            throw new RuntimeException('Material pack not found: '.self::REF_ID." (looked in {$path})");
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->pack = $decoded;
    }
}
