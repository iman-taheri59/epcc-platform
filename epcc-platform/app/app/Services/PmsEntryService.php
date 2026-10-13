<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\PipingClassNdtRequirement;
use App\Models\PipingClassNote;
use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Support\Reference\MaterialLibrary;
use App\Support\Reference\PipeReference;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Transcription, not class approval. Explicit references never silently replace source wording. */
final class PmsEntryService
{
    public const HEADER_TEXT = ['code' => 32, 'description_en' => 255, 'description_fa' => 255, 'pressure_rating' => 16,
        'service_en' => 255, 'service_fa' => 255, 'material_as_written' => 128, 'corrosion_allowance_as_written' => 16,
        'source_document' => 120, 'source_revision' => 60, 'source_text' => 10000,
        'material_group_code' => 32, 'material_spec_code' => 32, 'material_grade_key' => 48];

    public const ITEM_TEXT = ['component_type' => 32, 'qualifier' => 32, 'nps' => 16, 'material_group_code' => 32,
        'material_spec_code' => 32, 'material_grade_key' => 48, 'product_form' => 32, 'dimensional_standard' => 48,
        'schedule' => 16, 'pressure_rating' => 16, 'end_type' => 16, 'facing' => 16, 'description_as_written' => 10000, 'source_ref' => 128];

    public function __construct(private TenantContext $tenant, private ProjectFittingDefinitionService $access,
        private ProjectArchiveService $archives, private FittingReviewService $reviews,
        private MaterialLibrary $materials, private PipeReference $pipe, private PmsAccessService $authors, private PmsMeasurementService $measurements, private PmsValveEntryService $valves) {}

    public function canWrite(Project $p, User $a, ?PipingClass $class = null): bool
    {
        return $this->tenant->runAs($a->company_id, function () use ($p, $a, $class): bool {
            $m = $a->membershipFor($p->id);

            return $a->is_active && $m && ($m->discipline_scope === null || in_array('PIPING', $m->discipline_scope, true))
                && $this->archives->isWritable($p) && (! $class || ($class->company_id === $a->company_id && $class->project_id === $p->id))
                && $this->authors->allows($p, $a);
        }, $p->id);
    }

    public function scoped(Project $p, User $a, callable $fn, bool $write = false): mixed
    {
        $this->access->assertAccess($p, $a);

        return $this->tenant->runAs($a->company_id, fn () => DB::transaction(function () use ($p, $a, $fn, $write) {
            if ($write) {
                $this->archives->assertWritable($p);
                abort_unless(User::whereKey($a->id)->where('is_active', true)->exists(), 403);
                // Same lock order as definition workflow: no correction can race a selection.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['fitting-review:'.$p->id]);
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pms-entry:'.$p->id]);
                abort_unless($this->canWrite($p, $a), 403);
            }

            return $fn();
        }), $p->id);
    }

    public function authorAccess(Project $p, User $a): array
    {
        return $this->authors->dashboard($p, $a);
    }

    public function configureAuthors(Project $p, User $a, array $input): void
    {
        $this->authors->configure($p, $a, $input);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['pms' => __('pms.'.$key)]);
    }

    public function catalog(): array
    {
        $options = [];
        foreach ($this->materials->groups() as $group) {
            foreach ($this->materials->specsIn($group['code']) as $spec) {
                $grades = $this->materials->gradesOf($group['code'], $spec['code']);
                // A specification may be known while its grade remains unspecified; keep that incompleteness visible.
                foreach ([null, ...$grades] as $grade) {
                    $key = hash('sha256', json_encode([$group['code'], $spec['code'], $grade['key'] ?? null]));
                    $options[$key] = ['material_group_code' => $group['code'], 'material_spec_code' => $spec['code'], 'material_grade_key' => $grade['key'] ?? null,
                        'label' => $spec['designation'].($grade ? ' · '.$grade['key'] : ''), 'group' => $group, 'spec' => $spec, 'grade' => $grade,
                        'product_forms' => $this->materials->productFormsFor($group['code'], $spec['code']), 'source' => $this->materials->provenance()];
                }
            }
        }

        return ['materials' => $options, 'sizes' => $this->pipe->sizes(), 'templates' => $this->pipe->templates(),
            'end_types' => $this->pipe->endTypes(), 'provenance' => $this->pipe->provenance() + ['materials' => $this->materials->provenance()]];
    }

    public function definitions(Project $p, User $a): array
    {
        $rows = ProjectFittingDefinition::where('project_id', $p->id)->orderBy('created_at')->get();
        $out = [];
        foreach ($rows->groupBy('material_family') as $family => $familyRows) {
            $states = $this->reviews->dashboard($p, $a, $familyRows, $family)['reviewStates'];
            foreach ($familyRows as $d) {
                $out[$d->id] = ['row' => $d, 'state' => $states[$d->id]];
            }
        }

        return $out;
    }

    private function classFor(Project $p, string $id): PipingClass
    {
        return PipingClass::where('project_id', $p->id)->whereKey($id)->firstOrFail();
    }

    private function version(array $input, int $current): void
    {
        if ((int) ($input['version'] ?? -1) !== $current) {
            $this->fail('stale');
        }
    }

    private function audit(Project $p, User $a, PipingClass $class, ?PipingClassItem $item, ?array $before, string $reason): void
    {
        $target = $item ?? $class;
        $after = $target->fresh();
        if ($item) {
            $after->load('valveSpec');
        }
        if (! $item) {
            $after->load(['notes', 'ndtRequirements']);
        }
        DB::table('pms_entry_events')->insert(['id' => (string) Str::uuid(), 'project_id' => $p->id, 'company_id' => $a->company_id,
            'piping_class_id' => $class->id, 'item_id' => $item?->id, 'created_by' => $a->id, 'actor_name' => $a->name, 'created_at' => now(),
            'version' => $target->entry_version, 'reason' => $reason, 'before_data' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_data' => json_encode($after->toArray(), JSON_THROW_ON_ERROR)]);
    }

    public function saveHeader(Project $p, User $a, array $input, ?string $id = null): PipingClass
    {
        $rules = [];
        foreach (self::HEADER_TEXT as $key => $max) {
            $rules[$key] = [in_array($key, ['code', 'source_document', 'source_revision', 'source_text']) ? 'required' : 'nullable', 'string', 'max:'.$max];
        }
        $rules += ['material_key' => ['nullable', 'string', 'size:64'], 'version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:4000'],
            'fluid_service_category' => ['nullable', Rule::in(['NORMAL', 'CATEGORY_D', 'CATEGORY_M', 'HIGH_PRESSURE'])],
            'severe_cyclic_conditions' => ['nullable', 'boolean'], 'default_flange_series' => ['nullable', Rule::in(['A', 'B'])]];
        $v = Validator::make($input, $rules)->validate();
        foreach (['code', 'source_document', 'source_revision', 'source_text', 'reason'] as $k) {
            if (trim($v[$k]) === '') {
                $this->fail('source_required');
            }
        }
        $selection = null;
        if (! empty($v['material_key'])) {
            $selection = $this->catalog()['materials'][$v['material_key']] ?? null;
            if (! $selection) {
                $this->fail('reference_missing');
            }
            foreach (['material_group_code', 'material_spec_code', 'material_grade_key'] as $k) {
                $v[$k] = $selection[$k];
            }
        }
        if ((! empty($v['material_spec_code']) && empty($v['material_group_code'])) || (! empty($v['material_grade_key']) && empty($v['material_spec_code']))) {
            $this->fail('reference_order');
        }

        return $this->unique(fn () => $this->scoped($p, $a, function () use ($p, $a, $v, $id, $selection, $input) {
            $class = $id ? $this->classFor($p, $id) : new PipingClass;
            abort_unless($this->canWrite($p, $a, $id ? $class : null), 403);
            $this->version($v, (int) ($class->entry_version ?? 0));
            $before = $id ? $class->load(['notes', 'ndtRequirements'])->toArray() : null;
            foreach (self::HEADER_TEXT as $k => $max) {
                $class->{$k} = $v[$k] ?? null;
            }
            $this->measurements->apply($class, $input);
            $this->applySpecialRequirements($class, $input);
            foreach (['fluid_service_category', 'default_flange_series'] as $k) {
                $class->{$k} = $v[$k] ?? null;
            }
            $class->severe_cyclic_conditions = (bool) ($v['severe_cyclic_conditions'] ?? false);
            if (! $id) {
                $class->project_id = $p->id;
                $class->company_id = $a->company_id;
            }
            if ($selection) {
                $class->entry_provenance = ['material_selection' => $selection];
            } elseif ($class->entry_provenance) {
                foreach (['material_group_code', 'material_spec_code', 'material_grade_key'] as $k) {
                    if ($class->{$k} !== ($class->entry_provenance['material_selection'][$k] ?? null)) {
                        $class->entry_provenance = null;
                        break;
                    }
                }
            }
            $class->entry_version = (int) ($class->entry_version ?? 0) + 1;
            $class->save();
            $this->audit($p, $a, $class, null, $before, $v['reason']);

            return $class;
        }, true));
    }

    /** Preserve source wording; no standards adoption or engineering inference. */
    private function applySpecialRequirements(PipingClass $class, array $input): void
    {
        if (! array_key_exists('special_requirements_mode', $input)) {
            if (array_key_exists('special_requirements_text', $input)) {
                throw ValidationException::withMessages(['header.special_requirements_mode' => __('pms.special_mode_required')]);
            }

            return;
        }
        $mode = $input['special_requirements_mode'];
        if (! is_string($mode) || ! in_array($mode, ['keep', 'replace', 'clear'], true)) {
            throw ValidationException::withMessages(['header.special_requirements_mode' => __('pms.special_mode_required')]);
        }
        if ($mode === 'keep') {
            return;
        }
        if ($mode === 'clear') {
            $class->special_requirements = null;

            return;
        }
        $text = $input['special_requirements_text'] ?? null;
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 50100
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{009F}]/u', $text)) {
            throw ValidationException::withMessages(['header.special_requirements_text' => __('pms.special_invalid')]);
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $text), fn (string $line): bool => preg_match('/^[\s\p{Z}]*$/u', $line) !== 1));
        if ($lines === [] || count($lines) > 50 || count(array_filter($lines, fn (string $line): bool => mb_strlen($line) > 1000)) > 0) {
            throw ValidationException::withMessages(['header.special_requirements_text' => __('pms.special_invalid')]);
        }
        $class->special_requirements = $lines;
    }

    public function prepareItem(array $input): array
    {
        $rules = [];
        foreach (self::ITEM_TEXT as $k => $max) {
            $rules[$k] = [in_array($k, ['component_type', 'description_as_written', 'source_ref']) ? 'required' : 'nullable', 'string', 'max:'.$max];
        }
        $rules += ['dn' => ['nullable', 'integer', 'min:1', 'max:2147483647'], 'size_key' => ['nullable', 'string', 'max:16'],
            'material_key' => ['nullable', 'string', 'size:64'], 'project_definition_id' => ['nullable', 'uuid'],
            'version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:4000'],
            'note_numbers_text' => ['nullable', 'string', 'max:500']];
        $v = Validator::make($input, $rules)->validate();
        foreach (['component_type', 'description_as_written', 'source_ref', 'reason'] as $k) {
            if (trim($v[$k]) === '') {
                $this->fail('source_required');
            }
        }
        $catalog = $this->catalog();
        $library = null;
        $size = null;
        if (! empty($v['material_key'])) {
            $library = $catalog['materials'][$v['material_key']] ?? null;
            if (! $library) {
                $this->fail('reference_missing');
            }
            foreach (['material_group_code', 'material_spec_code', 'material_grade_key'] as $k) {
                $v[$k] = $library[$k];
            }
        }
        if (! empty($v['size_key'])) {
            if (! $this->pipe->hasSize($v['size_key'])) {
                $this->fail('reference_missing');
            }
            $size = $this->pipe->size($v['size_key']);
            $v['dn'] = $size->dn;
            $v['nps'] = $size->nps;
        }
        if (empty($v['dn'])) {
            $this->fail('dn_required');
        }
        if ((! empty($v['material_spec_code']) && empty($v['material_group_code'])) || (! empty($v['material_grade_key']) && empty($v['material_spec_code']))) {
            $this->fail('reference_order');
        }
        $notes = preg_split('/[\s,،]+/', trim($v['note_numbers_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($notes as $n) {
            if (! ctype_digit($n) || (int) $n < 1 || (int) $n > 2147483647) {
                $this->fail('notes_invalid');
            }
        }
        $v['note_numbers'] = array_values(array_unique(array_map('intval', $notes)));

        $valve = $this->valves->prepare($input);

        return compact('v', 'catalog', 'library', 'size', 'valve');
    }

    public function saveItem(Project $p, User $a, string $classId, array $input, ?string $id = null): PipingClassItem
    {
        ['v' => $v, 'catalog' => $catalog, 'library' => $library, 'size' => $size, 'valve' => $valve] = $this->prepareItem($input);

        return $this->unique(fn () => $this->scoped($p, $a, function () use ($p, $a, $classId, $id, $v, $catalog, $library, $size, $valve) {
            $class = $this->classFor($p, $classId);
            abort_unless($this->canWrite($p, $a, $class), 403);
            $item = $id ? PipingClassItem::where('project_id', $p->id)->where('piping_class_id', $classId)->whereKey($id)->firstOrFail() : new PipingClassItem;
            abort_if($id && $item->company_id !== $a->company_id, 403);
            $this->version($v, (int) ($item->entry_version ?? 0));
            $before = $id ? $item->load('valveSpec')->toArray() : null;
            $selected = null;
            $defId = $v['project_definition_id'] ?? null;
            // Retaining an old pinned link is permitted, but reselecting a superseded version is not.
            if ($defId) {
                $all = $this->definitions($p, $a);
                $d = $all[$defId] ?? null;
                if (! $d) {
                    $this->fail('definition_unavailable');
                }
                if ($id && $item->project_definition_id === $defId) {
                    $selected = $item->entry_provenance['project_definition'] ?? null;
                } else {
                    if ($d['state']['state'] !== 'APPROVED') {
                        $this->fail('definition_unavailable');
                    }
                    $selected = ['definition' => $d['row']->toArray(), 'state_at_selection' => 'APPROVED',
                        'run_id' => $d['state']['run']->id, 'policy_id' => $d['state']['policy']->id,
                        'approval_ids' => array_column($d['state']['decisions'], 'id'),
                        'screening_run_id' => $d['state']['screeningSnapshot']['record']->run_id ?? null];
                }
            }
            foreach (self::ITEM_TEXT as $k => $max) {
                $item->{$k} = $v[$k] ?? null;
            }
            $item->dn = $v['dn'];
            $item->note_numbers = $v['note_numbers'];
            $item->project_definition_id = $defId;
            $prior = $item->entry_provenance ?? [];
            $materialSelection = $library;
            if (! $library && isset($prior['material_selection'])) {
                $same = true;
                foreach (['material_group_code', 'material_spec_code', 'material_grade_key'] as $k) {
                    if ($item->{$k} !== ($prior['material_selection'][$k] ?? null)) {
                        $same = false;
                    }
                }
                if ($same) {
                    $materialSelection = $prior['material_selection'];
                }
            }
            $sizeSelection = $size?->toArray();
            if (! $size && isset($prior['size_selection']) && $item->dn === $prior['size_selection']['dn'] && $item->nps === $prior['size_selection']['nps']) {
                $sizeSelection = $prior['size_selection'];
            }
            $item->entry_provenance = ['mode' => 'EXPLICIT_TRANSCRIPTION', 'references' => $catalog['provenance'],
                'material_selection' => $materialSelection, 'size_selection' => $sizeSelection, 'project_definition' => $selected,
                'valve_selection' => $prior['valve_selection'] ?? null, 'valve_references' => $prior['valve_references'] ?? null];
            if (! $id) {
                $item->project_id = $p->id;
                $item->company_id = $a->company_id;
                $item->piping_class_id = $classId;
            }
            $item->entry_version = (int) ($item->entry_version ?? 0) + 1;
            $item->save();
            $this->valves->apply($item, $valve);
            $this->audit($p, $a, $class, $item, $before, $v['reason']);

            return $item;
        }, true));
    }

    public function saveNote(Project $p, User $a, string $classId, array $input): void
    {
        $v = Validator::make($input, ['version' => ['required', 'integer', 'min:0'], 'number' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'text_fa' => ['nullable', 'string', 'max:10000'], 'text_en' => ['nullable', 'string', 'max:10000'], 'reason' => ['required', 'string', 'max:4000']])->validate();
        if (trim($v['reason']) === '' || (trim($v['text_fa'] ?? '') === '' && trim($v['text_en'] ?? '') === '')) {
            $this->fail('source_required');
        }
        $this->scoped($p, $a, function () use ($p, $a, $classId, $v) {
            $class = $this->classFor($p, $classId);
            abort_unless($this->canWrite($p, $a, $class), 403);
            $this->version($v, (int) $class->entry_version);
            $before = $class->load(['notes', 'ndtRequirements'])->toArray();
            $note = PipingClassNote::where('piping_class_id', $classId)->where('number', $v['number'])->first();
            abort_if($note && $note->company_id !== $a->company_id, 403);
            $note ??= new PipingClassNote(['project_id' => $p->id, 'company_id' => $a->company_id, 'piping_class_id' => $classId, 'number' => $v['number']]);
            $note->text_fa = $v['text_fa'] ?? null;
            $note->text_en = $v['text_en'] ?? null;
            $note->save();
            $class->entry_version++;
            $class->save();
            $this->audit($p, $a, $class, null, $before, $v['reason']);
        }, true);
    }

    /** NDT requirements are class-owned source text, never incoming-material inspections. */
    public function saveNdt(Project $p, User $a, string $classId, array $input): PipingClassNdtRequirement
    {
        return $this->scoped($p, $a, function () use ($p, $a, $classId, $input) {
            $class = $this->classFor($p, $classId);
            abort_unless($this->canWrite($p, $a, $class), 403);
            $rules = ['ndt.id' => ['nullable', 'uuid'], 'ndt.version' => ['required', 'integer', 'min:0'],
                'ndt.reason' => ['required', 'string', 'max:4000']];
            foreach (PipingClassNdtRequirement::FIELDS + ['reason' => 4000] as $field => $limit) {
                $value = $input[$field] ?? null;
                if ($value !== null && (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{009F}]/u', $value))) {
                    throw ValidationException::withMessages(['ndt.'.$field => __('pms.ndt_invalid')]);
                }
                if ($value !== null && preg_match('/^[\s\p{Z}]*$/u', $value) === 1) {
                    $input[$field] = null;
                }
                $rules['ndt.'.$field] = [in_array($field, [...PipingClassNdtRequirement::REQUIRED, 'reason'], true) ? 'required' : 'nullable', 'string', 'max:'.$limit];
            }
            $v = Validator::make(['ndt' => $input], $rules)->validate()['ndt'];
            $this->version($v, (int) $class->entry_version);
            $record = ! empty($v['id']) ? PipingClassNdtRequirement::where('project_id', $p->id)
                ->where('piping_class_id', $classId)->whereKey($v['id'])->firstOrFail() : new PipingClassNdtRequirement;
            abort_if($record->exists && $record->company_id !== $a->company_id, 403);
            if (! $record->exists && $class->ndtRequirements()->count() >= 100) {
                throw ValidationException::withMessages(['ndt' => __('pms.ndt_limit')]);
            }
            $before = $class->load(['notes', 'ndtRequirements'])->toArray();
            foreach (PipingClassNdtRequirement::FIELDS as $field => $limit) {
                $record->{$field} = $v[$field] ?? null;
            }
            $record->project_id = $p->id;
            $record->company_id = $a->company_id;
            $record->piping_class_id = $classId;
            $record->save();
            $class->entry_version++;
            $class->save();
            $this->audit($p, $a, $class, null, $before, $v['reason']);

            return $record;
        }, true);
    }

    private function unique(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $this->fail('duplicate');
            }throw $e;
        }
    }

    /** Link mismatches are advisory; faithful transcription is not prevented. */
    public function linkedFindings(PipingClassItem $item, array $definitions): array
    {
        if (! $item->project_definition_id) {
            return [];
        }
        $d = $definitions[$item->project_definition_id] ?? null;
        $out = [];
        if (! $d) {
            return ['definition_unavailable'];
        }
        if ($d['state']['state'] !== 'APPROVED') {
            $out[] = 'definition_stale';
        }
        $r = $d['row'];
        $type = $r->component_type;
        if (($type === 'BOLTING' && ! in_array($item->component_type, ['BOLT', 'NUT'], true)) || ($type !== 'BOLTING' && $item->component_type !== $type)) {
            $out[] = 'component_conflict';
        }
        if ($r->material_family === 'FITTING') {
            if (! $this->pipe->resolveLabel((string) $item->nps) || (float) $this->pipe->resolveLabel((string) $item->nps)->npsDecimal !== (float) $r->nps) {
                $out[] = 'size_check';
            }
        } else {
            $out[] = 'size_check';
        } // Free source size wording must not be silently parsed as an engineering mapping.
        if ($r->qualifier && $item->qualifier !== $r->qualifier) {
            $out[] = 'qualifier_check';
        }
        if ($r->material_family !== 'FITTING') {
            if ($item->dimensional_standard !== $r->reference_standard) {
                $out[] = 'standard_check';
            }
            if ($item->material_spec_code !== $r->material_spec || ($r->material_grade && $item->material_grade_key !== $r->material_grade)) {
                $out[] = 'material_check';
            }
            foreach (['schedule', 'product_form', 'pressure_rating', 'facing'] as $key) {
                if (isset($r->properties[$key]) && $item->{$key} !== $r->properties[$key]) {
                    $out[] = $key.'_check';
                }
            }
        }
        $out[] = 'link_not_clearance';

        return $out;
    }
}
