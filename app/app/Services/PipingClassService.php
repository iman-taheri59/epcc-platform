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
use App\Support\Piping\PipingClassState;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\MaterialReference;
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
    public function __construct(private readonly MaterialReference $materials)
    {
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

            // A class that branches must say what the pipe is made of: every
            // PAD verdict depends on S(T) of the pipe material.
            $hasBranchRules = PipingClassBranchRule::where('piping_class_id', $locked->id)->exists();
            if ($hasBranchRules && ! $kinds->contains(PipingRuleKind::PIPE_MATERIAL)) {
                $this->fail($lang, 'rules',
                    'کلاسی که جدول انشعاب دارد باید قاعدهٔ جنس لوله (برای هر بازهٔ اندازه) داشته باشد.',
                    'A class with branch rules must carry a PIPE_MATERIAL rule covering its span.');
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
     * PIPE_MATERIAL value shape: {"spec": "A106", "grade": "B", "psl": null}
     * resolving against the B31.3-2024 reference pack, with optional manual
     * overrides (owner decision 2026-10-12): "s_mpa_override", "ej_override".
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
        }
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
