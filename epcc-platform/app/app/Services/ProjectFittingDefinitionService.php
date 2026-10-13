<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Support\Reference\FittingDimensions;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectFittingDefinitionService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FittingDimensions $reference,
        private readonly ProjectArchiveService $archives,
    ) {}

    public function assertAccess(Project $project, User $actor): void
    {
        abort_unless($actor->is_active, 403);
        $allowed = $this->tenant->runAs($actor->company_id,
            fn () => DB::table('project_company')->where('project_id', $project->id)
                ->where('company_id', $actor->company_id)->exists()
                && $actor->membershipFor($project->id) !== null, $project->id);
        abort_unless($allowed, 404);
    }

    public function create(Project $project, User $actor, array $input): ProjectFittingDefinition
    {
        $this->assertAccess($project, $actor);
        $this->archives->assertWritable($project);
        $data = $this->validateInput($input);
        // Preserve source wording. Do not infer OD/DN, angle/radius, approval or SI dimensions.
        try {
            return $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $data) {
                $row = new ProjectFittingDefinition($data);
                $row->project_id = $project->id;
                $row->company_id = $actor->company_id;
                $row->created_by = $actor->id;
                $row->provenance = 'PROJECT_SPEC';
                $row->review_state = 'UNREVIEWED';
                $row->save();

                return $row;
            }), $project->id);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['spec_number' => __('project_fittings.duplicate')]);
            }
            throw $e;
        }
    }
    public function validateInput(array $input): array
    {
        if (in_array($input['component_type'] ?? null, array_keys(ProjectMaterialSchema::FIELDS), true)) {
            return ProjectMaterialSchema::validate($input);
        }
        // Only declared fields are accepted; client-supplied ownership/state is ignored.
        $rules = [
            'component_type' => ['required', Rule::in(['ELBOW', 'CAP'])],
            'qualifier' => ['nullable', 'string', 'max:80'],
            'nps' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'lt:100000000'],
            'dn' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'od_mm' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'lt:10000000000'],
            'dimension' => ['required', Rule::in(['LENGTH', 'CENTRE_TO_END'])],
            'dimension_mm' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'lt:10000000000'],
            'spec_number' => ['required', 'string', 'max:120'],
            'spec_revision' => ['required', 'string', 'max:60'],
            'spec_clause' => ['required', 'string', 'max:120'],
            'source_text' => ['required', 'string', 'max:10000'],
        ];
        $messages = [];
        foreach (['required','numeric','decimal','gt','lt','integer','min','max','string','in'] as $rule) {
            $messages[$rule] = __('project_fittings.validation_'.$rule);
        }
        $attributes = [];
        foreach (array_keys($rules) as $field) { $attributes[$field] = __('project_fittings.'.$field); }
        $data = Validator::make($input, $rules, $messages, $attributes)->validate();
        if ($this->reference->sizeScope((string) $data['nps']) !== 'PROJECT_SPEC_REQUIRED') {
            throw ValidationException::withMessages(['nps' => __('project_fittings.outside_only')]);
        }
        $data['qualifier'] = trim((string) ($data['qualifier'] ?? ''));
        $cap = $data['component_type'] === 'CAP';
        if (($cap && $data['qualifier'] !== '') || (! $cap && $data['qualifier'] === '')
            || $data['dimension'] !== ($cap ? 'LENGTH' : 'CENTRE_TO_END')) {
            throw ValidationException::withMessages(['dimension' => __('project_fittings.geometry_error')]);
        }
        foreach (['spec_number', 'spec_revision', 'spec_clause', 'source_text'] as $field) {
            if (trim($data[$field]) === '') {
                throw ValidationException::withMessages([$field => __('project_fittings.source_required')]);
            }
        }
        $data['material_family'] = 'FITTING';
        return $data;
    }

}
