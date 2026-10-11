<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassBranchRule;
use App\Models\PipingClassRule;
use App\Models\Project;
use App\Models\User;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\Exceptions\BranchLargerThanHeaderException;
use App\Support\Piping\Exceptions\NoRuleForSizeException;
use App\Support\Piping\PipingClassState;
use App\Support\Piping\PipingRuleKind;
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
