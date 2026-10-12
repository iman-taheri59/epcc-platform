<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassBranchRule;
use App\Models\PipingClassRule;
use App\Models\Project;
use App\Models\User;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\BranchReinforcement;
use App\Support\Piping\Exceptions\BranchLargerThanHeaderException;
use App\Support\Piping\P2pKind;
use App\Support\Piping\Exceptions\NoRuleForSizeException;
use App\Support\Piping\Exceptions\PadGateRefusal;
use App\Support\Piping\PipingClassState;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\Exceptions\FlangeRatingOutOfRangeException;
use App\Support\Reference\Exceptions\UnknownFlangeMaterialException;
use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\FlangeRatingReference;
use App\Support\Reference\MaterialReference;
use App\Support\Reference\PipeReference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-008 (piping phase P1) — the PMS as data.
 *
 * Overlap of one-dimensional rules is refused by the database (EXCLUDE
 * constraint); gaps are refused here, at the publish gate, by `range_agg`
 * coverage of the class's declared span. Branch rules overlap by design and
 * resolve by specificity — narrowest footprint first, `priority` as the
 * explicit tiebreak. Resolvers THROW on a miss: a silent null becomes a
 * silent default schedule, which becomes a wrong wall thickness on a
 * pressure line.
 */
final class PipingClassService
{
    public function __construct(
        private readonly MaterialReference $materials,
        private readonly FlangeRatingReference $flangeRatings,
        private readonly PipeReference $sizes,
        private readonly BranchConnectionPadGate $padGate,
    ) {
    }

    // ------------------------------------------------------------------
    // Drafting
    // ------------------------------------------------------------------

    /** @throws ValidationException */
    public function create(
        Project $project,
        User $user,
        string $code,
        string $nameEn,
        string $nameFa,
        int $spanFrom,
        int $spanTo,
        string $lang = 'fa',
    ): PipingClass {
        $this->assertBand($spanFrom, $spanTo, $lang, 'dn_span');

        try {
            return PipingClass::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'code' => $code,
                'name_en' => $nameEn,
                'name_fa' => $nameFa,
                'state' => PipingClassState::DRAFT,
                'dn_span' => "[{$spanFrom},{$spanTo})",
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->fail($lang, 'code',
                    'کلاس پایپینگ با این کد قبلاً ثبت شده است.',
                    'A piping class with this code already exists.');
            }

            throw $e;
        }
    }

    /**
     * ADR-008 Step B (owner decision 2026-10-12): the design basis is a
     * CLASS attribute — pressure in MPa, temperature in deg C. Per-line
     * design columns remain for line-level checks; this pair drives the
     * pad-validation math of para 304.3.3.
     *
     * @throws ValidationException
     */
    public function setDesignBasis(
        Project $project,
        User $user,
        PipingClass $class,
        float $pressureMpa,
        float $temperatureC,
        string $lang = 'fa',
    ): PipingClass {
        $this->assertDraft($class, $lang);

        if ($pressureMpa <= 0) {
            $this->fail($lang, 'design_pressure_mpa',
                'فشار طراحی باید بزرگ‌تر از صفر باشد (مگا‌پاسکال).',
                'Design pressure must be greater than zero (MPa).');
        }

        if ($temperatureC < -273.15 || $temperatureC > 1100) {
            $this->fail($lang, 'design_temperature_c',
                'دمای طراحی خارج از محدودهٔ فیزیکی مجاز است.',
                'Design temperature is outside the physically valid range.');
        }

        $class->update([
            'design_pressure_mpa' => $pressureMpa,
            'design_temperature_c' => $temperatureC,
        ]);

        return $class->refresh();
    }

    /**
     * @param  array<string,mixed>  $value
     * @throws ValidationException
     */
    public function addRule(
        Project $project,
        User $user,
        PipingClass $class,
        string $kind,
        int $dnFrom,
        int $dnTo,
        array $value,
        string $lang = 'fa',
        ?string $noteEn = null,
        ?string $noteFa = null,
        ?string $sourceRef = null,
    ): PipingClassRule {
        $this->assertDraft($class, $lang);

        if (! in_array($kind, PipingRuleKind::ALL, true)) {
            $this->fail($lang, 'rule_kind',
                'نوع قاعده نامعتبر است.',
                'Unknown rule kind.');
        }

        $this->assertBand($dnFrom, $dnTo, $lang, 'dn_range');

        if ($value === []) {
            $this->fail($lang, 'value',
                'قاعده باید مقدار داشته باشد.',
                'A rule must carry a value.');
        }

        if ($kind === PipingRuleKind::PIPE_MATERIAL) {
            $this->assertPipeMaterialValue($value, $lang);
        }

        if ($kind === PipingRuleKind::FLANGE_TYPE) {
            $this->assertFlangeTypeValue($value, $lang);
        }

        $id = (string) Str::uuid();

        try {
            DB::table('piping_class_rules')->insert([
                'id' => $id,
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'piping_class_id' => $class->id,
                'rule_kind' => $kind,
                'dn_range' => "[{$dnFrom},{$dnTo})",
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'note_en' => $noteEn,
                'note_fa' => $noteFa,
                'source_ref' => $sourceRef,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isExclusionViolation($e)) {
                $this->fail($lang, 'dn_range',
                    'این بازهٔ اندازه با قاعده‌ای موجود از همین نوع هم‌پوشانی دارد؛ باندها باید نیمه‌باز و بدون هم‌پوشانی باشند.',
                    'This size band overlaps an existing rule of the same kind; bands are half-open and must not overlap.');
            }

            throw $e;
        }

        return PipingClassRule::findOrFail($id);
    }

    /**
     * @param  array<string,mixed>|null  $value
     * @param  float|null  $padThickness  millimetres; MANDATORY when $reinforcement is PAD
     * @throws ValidationException
     */
    public function addBranchRule(
        Project $project,
        User $user,
        PipingClass $class,
        int $headerFrom,
        int $headerTo,
        int $branchFrom,
        int $branchTo,
        string $componentType,
        string $lang = 'fa',
        ?string $p2pKind = null,
        string $reinforcement = BranchReinforcement::NONE,
        ?float $padThickness = null,
        ?array $value = null,
        ?string $sourceRef = null,
        int $priority = 0,
    ): PipingClassBranchRule {
        $this->assertDraft($class, $lang);
        $this->assertBand($headerFrom, $headerTo, $lang, 'header_range');
        $this->assertBand($branchFrom, $branchTo, $lang, 'branch_range');

        if (! in_array($componentType, BranchConnectionType::ALL, true)) {
            $this->fail($lang, 'component_type',
                'نوع اتصال انشعاب نامعتبر است؛ انواع مجاز: '.implode('، ', array_map(
                    static fn (string $t) => BranchConnectionType::label($t)['fa'],
                    BranchConnectionType::ALL
                )),
                'Invalid branch connection type; allowed: '.implode(', ', BranchConnectionType::ALL));
        }

        // A P2P cell must name its kind; a fitting cell must not carry one.
        if ($componentType === BranchConnectionType::P2P) {
            if ($p2pKind === null || ! in_array($p2pKind, P2pKind::ALL, true)) {
                $this->fail($lang, 'p2p_kind',
                    'قاعدهٔ لوله به لوله باید نوع خود را مشخص کند: '.implode(' یا ', array_map(
                        static fn (string $k) => P2pKind::label($k)['fa'],
                        P2pKind::ALL
                    )),
                    'A Pipe-to-Pipe rule must name its kind: '.implode(' or ', array_map(
                        static fn (string $k) => P2pKind::label($k)['en'],
                        P2pKind::ALL
                    )));
            }
        } elseif ($p2pKind !== null) {
            $this->fail($lang, 'p2p_kind',
                'نوع P2P فقط برای اتصال لوله به لوله معنا دارد.',
                'A P2P kind only applies to Pipe-to-Pipe connections.');
        }

        if (! in_array($reinforcement, BranchReinforcement::ALL, true)) {
            $this->fail($lang, 'reinforcement',
                'نوع تقویت نامعتبر است؛ مقادیر مجاز: بدون تقویت یا پد تقویتی.',
                'Invalid reinforcement; allowed: none or pad.');
        }

        // Owner rule: a reinforcement pad without its thickness is an
        // incomplete datum. And a stray thickness without a pad contradicts
        // itself — both refused at the point of entry, backstopped by CHECK.
        if ($reinforcement === BranchReinforcement::PAD) {
            if ($padThickness === null || $padThickness <= 0) {
                $this->fail($lang, 'pad_thickness',
                    'در صورت نیاز به پد تقویتی، ضخامت پد (میلی‌متر) الزامی است.',
                    'A reinforcement pad requires its thickness in millimetres.');
            }
        } elseif ($padThickness !== null) {
            $this->fail($lang, 'pad_thickness',
                'ضخامت پد بدون انتخاب پد تقویتی معنا ندارد.',
                'A pad thickness without a reinforcement pad is contradictory.');
        }

        $id = (string) Str::uuid();

        try {
            DB::table('piping_class_branch_rules')->insert([
                'id' => $id,
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'piping_class_id' => $class->id,
                'header_range' => "[{$headerFrom},{$headerTo})",
                'branch_range' => "[{$branchFrom},{$branchTo})",
                'component_type' => $componentType,
                'p2p_kind' => $p2pKind,
                'reinforcement' => $reinforcement,
                'pad_thickness' => $padThickness,
                'value' => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE),
                'source_ref' => $sourceRef,
                'priority' => $priority,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $this->fail($lang, 'branch_rule',
                    'قاعده‌ای با همین اثرِ انگشت دقیق (بازهٔ هدر × بازهٔ انشعاب) قبلاً ثبت شده است.',
                    'A branch rule with this exact footprint (header range × branch range) already exists.');
            }

            throw $e;
        }

        return PipingClassBranchRule::findOrFail($id);
    }

    // ------------------------------------------------------------------
    // The publish gate (ADR-008 §3.1)
    // ------------------------------------------------------------------

    /** @throws ValidationException */
    public function publish(Project $project, User $user, PipingClass $class, string $lang = 'fa'): PipingClass
    {
        return DB::transaction(function () use ($project, $user, $class, $lang): PipingClass {
            $locked = PipingClass::whereKey($class->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPublished()) {
                $this->fail($lang, 'state',
                    'این کلاس پایپینگ قبلاً منتشر شده است؛ کلاس منتشرشده فقط‌خواندنی است.',
                    'This piping class is already published; a published class is read-only.');
            }

            $kinds = PipingClassRule::where('piping_class_id', $locked->id)
                ->distinct()
                ->pluck('rule_kind');

            if ($kinds->isEmpty()) {
                $this->fail($lang, 'rules',
                    'کلاس بدون هیچ قاعده‌ای قابل انتشار نیست.',
                    'A class with no rules cannot be published.');
            }

            $gaps = [];

            foreach ($kinds as $kind) {
                // span minus the aggregated bands: anything left is a hole.
                // The span is cast to a multirange first — PostgreSQL has
                // `multirange − multirange` but no `range − multirange`
                // operator (verified against PostgreSQL 16).
                $missing = DB::selectOne(<<<'SQL'
                    SELECT (SELECT dn_span::int4multirange FROM piping_classes WHERE id = ?)
                           - range_agg(dn_range) AS missing
                    FROM piping_class_rules
                    WHERE piping_class_id = ? AND rule_kind = ?
                SQL, [$locked->id, $locked->id, $kind]);

                $missingText = trim((string) ($missing->missing ?? ''));

                if ($missingText !== '' && $missingText !== '{}') {
                    $gaps[] = PipingRuleKind::label($kind)['en'].': '.$missingText;
                }
            }

            if ($gaps !== []) {
                $this->fail($lang, 'rules',
                    'پوشش بازهٔ اعلامی کلاس کامل نیست: '.implode('؛ ', $gaps),
                    'The declared span is not fully covered: '.implode('; ', $gaps));
            }

            // ADR-008 Step B: the design basis feeds the para 304.3.3 pad
            // check — a class without it cannot be published.
            if ($locked->design_pressure_mpa === null || $locked->design_temperature_c === null) {
                $this->fail($lang, 'design_basis',
                    'مبانی طراحی کلاس (فشار و دمای طراحی) باید پیش از انتشار ثبت شود.',
                    'The class design basis (design pressure and temperature) must be recorded before publishing.');
            }

            // ADR-008 P-24 (flange step): every FLANGE_TYPE cell must survive
            // the class's own design conditions per the ASME B16.5-2025 P-T
            // rating tables - an under-rated flange class is a publish stopper.
            $flangeViolations = $this->validateFlangeRatings($locked);
            if ($flangeViolations !== []) {
                $this->fail($lang, 'flange_rules',
                    'درجه‌بندی فشار-دمای فلنج‌ها طبق جدول‌های استاندارد ASME B16.5-2025 مردود است: '.implode('؛ ', $flangeViolations),
                    'The flange pressure-temperature ratings fail ASME B16.5-2025: '.implode('; ', $flangeViolations));
            }

            // A class that branches must say what the pipe is made of: every
            // PAD verdict depends on S(T) of the pipe material.
            $hasBranchRules = PipingClassBranchRule::where('piping_class_id', $locked->id)->exists();
            if ($hasBranchRules && ! $kinds->contains(PipingRuleKind::PIPE_MATERIAL)) {
                $this->fail($lang, 'rules',
                    'کلاسی که جدول انشعاب دارد باید قاعدهٔ جنس لوله (برای هر بازهٔ اندازه) داشته باشد.',
                    'A class with branch rules must carry a PIPE_MATERIAL rule covering its span.');
            }

            // THE P-24 GATE: every branch-table cell is checked against the
            // worst case of its footprint per ASME B31.3 para 304.3.3 —
            // recorded pads must be sufficient, NONE must be genuinely
            // adequate, and anything uncheckable is refused.
            if ($hasBranchRules) {
                $padViolations = $this->validateBranchPads($locked);
                if ($padViolations !== []) {
                    $this->fail($lang, 'branch_rules',
                        'جدول انشعاب طبق بند 304.3.3 استاندارد ASME B31.3 مردود است: '.implode('؛ ', $padViolations),
                        'The branch table fails ASME B31.3 para 304.3.3: '.implode('; ', $padViolations));
                }
            }

            $locked->update([
                'state' => PipingClassState::PUBLISHED,
                'published_at' => now(),
                'published_by' => $user->id,
            ]);

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------
    // Resolution — lookups, not calculations (ADR-008 §3.2, §4.3)
    // ------------------------------------------------------------------

    /** @throws NoRuleForSizeException */
    public function ruleFor(PipingClass $class, string $kind, int $dn): PipingClassRule
    {
        $rule = PipingClassRule::where('piping_class_id', $class->id)
            ->where('rule_kind', $kind)
            ->whereRaw('dn_range @> ?::int', [$dn])
            ->first();

        if ($rule === null) {
            throw new NoRuleForSizeException($class->id, $kind, $dn);
        }

        return $rule;
    }

    /** @throws BranchLargerThanHeaderException|NoRuleForSizeException */
    public function branchFor(PipingClass $class, int $headerDn, int $branchDn): PipingClassBranchRule
    {
        if ($branchDn > $headerDn) {
            throw new BranchLargerThanHeaderException($headerDn, $branchDn);
        }

        $rule = PipingClassBranchRule::where('piping_class_id', $class->id)
            ->whereRaw('header_range @> ?::int', [$headerDn])
            ->whereRaw('branch_range @> ?::int', [$branchDn])
            ->orderByRaw(
                '(upper(header_range) - lower(header_range)) + (upper(branch_range) - lower(branch_range)) ASC, '.
                'priority DESC, created_at ASC'
            )
            ->first();

        if ($rule === null) {
            throw new NoRuleForSizeException($class->id, 'branch', $headerDn);
        }

        return $rule;
    }

    // ------------------------------------------------------------------

    /**
     * Runs the para 304.3.3 gate over every branch rule of the class and
     * returns human-readable violations (empty = the table is sound).
     *
     * @return list<string>
     */
    private function validateBranchPads(PipingClass $class): array
    {
        $violations = [];

        $scheduleFor = function (int $dn) use ($class): string {
            try {
                $value = $this->ruleFor($class, PipingRuleKind::PIPE_SCHEDULE, $dn)->value;
            } catch (NoRuleForSizeException) {
                throw new PadGateRefusal("no PIPE_SCHEDULE rule covers DN{$dn}");
            }

            $schedule = $value['schedule'] ?? null;
            if (! is_string($schedule) && ! is_int($schedule)) {
                throw new PadGateRefusal("the PIPE_SCHEDULE rule at DN{$dn} lacks a 'schedule' value");
            }

            return (string) $schedule;
        };

        $caFor = function (int $dn) use ($class): float {
            try {
                $value = $this->ruleFor($class, PipingRuleKind::CORROSION_ALLOWANCE, $dn)->value;
            } catch (NoRuleForSizeException) {
                throw new PadGateRefusal("no CORROSION_ALLOWANCE rule covers DN{$dn}");
            }

            $ca = $value['ca_mm'] ?? null;
            if (! is_numeric($ca) || (float) $ca < 0) {
                throw new PadGateRefusal("the CORROSION_ALLOWANCE rule at DN{$dn} lacks a non-negative 'ca_mm' value");
            }

            return (float) $ca;
        };

        $materialFor = function (int $dn) use ($class): array {
            try {
                $value = $this->ruleFor($class, PipingRuleKind::PIPE_MATERIAL, $dn)->value;
            } catch (NoRuleForSizeException) {
                throw new PadGateRefusal("no PIPE_MATERIAL rule covers DN{$dn}");
            }

            return [
                'spec' => (string) ($value['spec'] ?? ''),
                'grade' => (string) ($value['grade'] ?? ''),
                'psl' => isset($value['psl']) && is_string($value['psl']) ? $value['psl'] : null,
                'pipe_type' => isset($value['pipe_type']) && is_string($value['pipe_type']) ? $value['pipe_type'] : null,
                's_mpa_override' => isset($value['s_mpa_override']) && is_numeric($value['s_mpa_override']) ? (float) $value['s_mpa_override'] : null,
                'ej_override' => isset($value['ej_override']) && is_numeric($value['ej_override']) ? (float) $value['ej_override'] : null,
                'p_no' => isset($value['p_no']) && is_string($value['p_no']) ? $value['p_no'] : null,
                'group_no' => isset($value['group_no']) && is_numeric($value['group_no']) ? (string) $value['group_no'] : null,
            ];
        };

        $rules = PipingClassBranchRule::where('piping_class_id', $class->id)
            ->orderByRaw('lower(header_range) ASC, lower(branch_range) ASC')
            ->get();

        foreach ($rules as $rule) {
            $result = $this->padGate->checkCell(
                pressureMpa: (float) $class->design_pressure_mpa,
                temperatureC: (float) $class->design_temperature_c,
                headerDns: $this->dnsIn((string) $rule->header_range),
                branchDns: $this->dnsIn((string) $rule->branch_range),
                scheduleFor: $scheduleFor,
                caFor: $caFor,
                materialFor: $materialFor,
                reinforcement: $rule->reinforcement,
                padThickness: $rule->pad_thickness === null ? null : (float) $rule->pad_thickness,
            );

            foreach ($result['violations'] as $violation) {
                $violations[] = sprintf(
                    '[%s x %s %s] %s',
                    (string) $rule->header_range, (string) $rule->branch_range,
                    $rule->reinforcement === 'PAD' ? 'PAD '.number_format((float) $rule->pad_thickness, 3).' mm' : 'NONE',
                    $violation
                );
            }
        }

        return $violations;
    }

    /**
     * The platform's DN vocabulary (the sizes pack) intersected with a
     * half-open range written in its canonical "[lo,hi)" text form.
     *
     * @return list<int>
     */
    private function dnsIn(string $range): array
    {
        if (preg_match('/^\\[(\\d+),(\\d+)\\)$/', trim($range), $m) !== 1) {
            return [];
        }

        $lo = (int) $m[1];
        $hi = (int) $m[2];

        return array_values(array_filter(
            array_map(static fn ($size) => $size->dn, $this->sizes->sizes()),
            static fn (int $dn): bool => $dn >= $lo && $dn < $hi
        ));
    }

    /**
     * PIPE_MATERIAL value shape: {"spec": "A106", "grade": "B", "psl": null}
     * resolving against the B31.3-2024 reference pack, with optional manual
     * overrides (owner decision 2026-10-12): "s_mpa_override", "ej_override".
     * Section IX identity: "p_no" ("1", "5A", "15E"...) and "group_no" are
     * optional for library materials (the pack carries both — P-No from
     * Table A-1, Group No from Section IX QW/QB-422) and MANDATORY for
     * materials outside the library (owner 2026-10-12).
     *
     * @param  array<string,mixed>  $value
     * @throws ValidationException
     */
    private function assertPipeMaterialValue(array $value, string $lang): void
    {
        $spec = $value['spec'] ?? null;
        $grade = $value['grade'] ?? null;

        if (! is_string($spec) || $spec === '' || ! is_string($grade) || $grade === '') {
            $this->fail($lang, 'value',
                'قاعدهٔ جنس لوله باید مشخصهٔ استاندارد (مثلاً A106) و گرید (مثلاً B) را داشته باشد.',
                'A PIPE_MATERIAL rule needs a spec (e.g. A106) and a grade (e.g. B).');
        }

        $psl = $value['psl'] ?? null;
        if ($psl !== null && (! is_string($psl) || $psl === '')) {
            $this->fail($lang, 'value',
                'سطح PSL باید رشته‌ای مانند PSL1 یا PSL2 باشد.',
                'The PSL level must be a string such as PSL1 or PSL2.');
        }

        foreach (['s_mpa_override', 'ej_override'] as $key) {
            $override = $value[$key] ?? null;
            if ($override !== null && (! is_numeric($override) || (float) $override <= 0)) {
                $this->fail($lang, $key,
                    'مقدار دستی واردشده باید عددی بزرگ‌تر از صفر باشد.',
                    "The {$key} must be a number greater than zero.");
            }
        }

        // Welding-identity data (owner requirement 2026-10-12): the Section
        // IX P-Number and Group number. Table A-1 publishes the P-No, so a
        // library material gets it from the pack; for a material OUTSIDE the
        // pack (manual S override) BOTH must be entered.
        $pNo = $value['p_no'] ?? null;
        if ($pNo !== null && (! is_string($pNo) || preg_match('/^[1-9]\d*(?:[A-Z]{1,2})?$/', $pNo) !== 1)) {
            $this->fail($lang, 'p_no',
                'شمارهٔ P باید به شکل چاپی استاندارد باشد؛ مانند 1 یا 5A یا 15E.',
                'The P-No must look like the printed Section IX value, e.g. 1, 5A or 15E.');
        }

        $groupNo = $value['group_no'] ?? null;
        if ($groupNo !== null && (! is_numeric($groupNo) || (int) $groupNo <= 0)) {
            $this->fail($lang, 'group_no',
                'شمارهٔ گروه (Group No) باید عددی صحیح و بزرگ‌تر از صفر باشد.',
                'The Group No must be a positive integer.');
        }

        // Without an S override the grade must exist in the reference pack —
        // a typo'd grade would otherwise surface only at publish time.
        if (($value['s_mpa_override'] ?? null) === null) {
            try {
                $this->materials->find($spec, $grade, $psl);
            } catch (UnknownMaterialException $e) {
                $this->fail($lang, 'value',
                    'جنس لوله در جدول A-1 استاندارد B31.3-2024 یافت نشد: '.$e->getMessage(),
                    'Pipe material not found in the B31.3-2024 Table A-1 pack: '.$e->getMessage());
            }
        } elseif ($pNo === null || $groupNo === null) {
            $this->fail($lang, 'value',
                'جنس خارج از کتابخانهٔ متریال باید شمارهٔ P و شمارهٔ گروه (طبق بخش IX) همراه داشته باشد.',
                'A material outside the library must carry its Section IX P-No and Group No.');
        }
    }

    /**
     * FLANGE_TYPE value shape: {"rating_class": 300, "spec": "A105",
     * "grade": "F316"|null} - the rating class must sit on B16.5's ladder
     * (150..2500) and the material must resolve to a material group in the
     * flange-ratings reference pack: no group, no rating table, no rule.
     *
     * @param  array<string,mixed>  $value
     * @throws ValidationException
     */
    private function assertFlangeTypeValue(array $value, string $lang): void
    {
        $ladder = $this->flangeRatings->classes();
        $ladderText = implode('/', array_map(strval(...), $ladder));

        $ratingClass = $value['rating_class'] ?? null;
        if (! is_numeric($ratingClass) || ! in_array((int) $ratingClass, $ladder, true)) {
            $this->fail($lang, 'rating_class',
                'کلاس فشار فلنج باید یکی از کلاس‌های استاندارد B16.5 باشد: '.$ladderText.'.',
                'The flange rating class must be one of the B16.5 classes: '.$ladderText.'.');
        }

        $spec = $value['spec'] ?? null;
        if (! is_string($spec) || trim($spec) === '') {
            $this->fail($lang, 'spec',
                'قاعدهٔ فلنج باید مشخصهٔ مادهٔ فلنج (مثلاً A105 یا A182) را داشته باشد.',
                'A FLANGE_TYPE rule needs the flange material spec (e.g. A105 or A182).');
        }

        $grade = $value['grade'] ?? null;
        if ($grade !== null && (! is_string($grade) || trim($grade) === '')) {
            $this->fail($lang, 'grade',
                'گرید مادهٔ فلنج در صورت درج باید رشته‌ای غیرخالی باشد.',
                'The flange material grade, when present, must be a non-empty string.');
        }

        try {
            $this->flangeRatings->groupFor($spec, $grade);
        } catch (UnknownFlangeMaterialException $e) {
            $this->fail($lang, 'spec',
                'مادهٔ فلنج در فهرست گروه‌های مواد استاندارد B16.5-2025 یافت نشد: '.$e->getMessage(),
                'Flange material not found in the B16.5-2025 material-group index: '.$e->getMessage());
        }
    }

    /**
     * ADR-008 P-24 (flange step): every FLANGE_TYPE cell must carry the
     * class's design pressure at the class's design temperature per its
     * B16.5 material-group rating table. Mirrors validateBranchPads -
     * anything uncheckable refuses; silence is never a pass.
     *
     * @return list<string>
     */
    private function validateFlangeRatings(PipingClass $class): array
    {
        $rules = PipingClassRule::where('piping_class_id', $class->id)
            ->where('rule_kind', PipingRuleKind::FLANGE_TYPE)
            ->orderByRaw('lower(dn_range) ASC')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $requiredBar = ((float) $class->design_pressure_mpa) * 10.0;
        $temperatureC = (float) $class->design_temperature_c;
        $violations = [];

        foreach ($rules as $rule) {
            $label = (string) $rule->dn_range;
            $value = is_array($rule->value) ? $rule->value : [];

            $ratingClass = $value['rating_class'] ?? null;
            $spec = $value['spec'] ?? null;
            $grade = $value['grade'] ?? null;

            if (! is_numeric($ratingClass) || ! is_string($spec) || trim($spec) === '') {
                $violations[] = "[{$label}] the FLANGE_TYPE rule needs a rating_class and a material spec";
                continue;
            }

            try {
                $group = $this->flangeRatings->groupFor($spec, is_string($grade) ? $grade : null);
                $ratedBar = $this->flangeRatings->maxPressureBar($group, (int) $ratingClass, $temperatureC);
            } catch (UnknownFlangeMaterialException|FlangeRatingOutOfRangeException $e) {
                $violations[] = "[{$label}] {$e->getMessage()}";
                continue;
            }

            if ($ratedBar + 1e-9 < $requiredBar) {
                $violations[] = sprintf(
                    '[%s] Class %s %s is rated %.1f bar at %.0f deg C; the design needs %.1f bar (%.2f MPa)',
                    $label,
                    (int) $ratingClass,
                    trim($spec.' '.(is_string($grade) ? $grade : '')),
                    $ratedBar,
                    $temperatureC,
                    $requiredBar,
                    (float) $class->design_pressure_mpa
                );
            }
        }

        return $violations;
    }

    /** @throws ValidationException */
    private function assertDraft(PipingClass $class, string $lang): void
    {
        if ($class->isPublished()) {
            $this->fail($lang, 'state',
                'کلاس منتشرشده فقط‌خواندنی است؛ اصلاحات باید در بازنگری جدید ثبت شود.',
                'A published class is read-only; corrections belong to a new revision.');
        }
    }

    /** @throws ValidationException */
    private function assertBand(int $from, int $to, string $lang, string $field): void
    {
        if ($from <= 0 || $to <= 0) {
            $this->fail($lang, $field,
                'کران‌های بازهٔ DN باید بزرگ‌تر از صفر باشند.',
                'DN range bounds must be greater than zero.');
        }

        if ($from >= $to) {
            $this->fail($lang, $field,
                'بازهٔ نیمه‌باز باید [از، تا) باشد با «از» کوچک‌تر از «تا».',
                'A half-open band must be [from, to) with from < to.');
        }
    }

    private function isExclusionViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'conflicting key value')
            || str_contains($e->getMessage(), 'exclusion');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'unique')
            || str_contains($e->getMessage(), 'duplicate key');
    }

    /** @throws ValidationException */
    private function fail(string $lang, string $field, string $fa, string $en): never
    {
        throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);
    }
}
