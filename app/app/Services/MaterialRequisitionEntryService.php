<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** ADR-018: authorized human entry facade around the legacy procurement primitive. */
final class MaterialRequisitionEntryService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $scope,
        private readonly ProjectArchiveService $archives,
        private readonly MaterialRequisitionAccessService $access,
        private readonly ProcurementService $procurement,
    ) {}

    public function create(Project $project, User $actor, array $input, string $lang = 'fa'): MaterialRequisition
    {
        $this->scope->assertAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $input, $lang): MaterialRequisition {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['mr-entry:'.$project->id]);
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($project);
            abort_unless($this->access->allows($project, $actor), 403, __('mr.not_author'));
            $data = $this->validate($input, $project);
            $policyId = $this->access->policyId($project, $actor, $data['discipline']);
            abort_unless($policyId !== null, 403, __('mr.not_author'));
            $mr = $this->procurement->raiseRequisition($project, $actor, $data['title_en'], $data['title_fa'], $data['discipline'], $data['lines'], $lang);
            $mr->author_policy_id = $policyId;
            $mr->save();
            foreach ($mr->items()->orderBy('line_no')->get() as $i => $line) {
                $line->source_quantity = $data['lines'][$i]['quantity'];
                $line->save();
            }

            return $mr->refresh();
        }), $project->id);
    }

    private function validate(array $input, Project $project): array
    {
        Validator::make($input, ['lines' => ['required', 'array', 'list', 'max:20'], 'lines.*' => ['array:item_code,description_en,description_fa,unit,quantity,specification,subsystem_id']])->validate();
        $input['lines'] = array_filter($input['lines'], fn (array $line): bool => collect($line)->contains(fn ($value): bool => $value !== null && $value !== ''));
        $text = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) === '') {
                $fail(__('mr.blank'));
            }
        };
        $data = Validator::make($input, [
            'title_en' => ['required', 'string', 'max:255', $text],
            'title_fa' => ['required', 'string', 'max:255', $text],
            'discipline' => ['required', 'string', 'max:32', 'regex:/\A[A-Z][A-Z0-9_]*\z/'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.item_code' => ['required', 'string', 'max:64', $text],
            'lines.*.description_en' => ['required', 'string', 'max:4000', $text],
            'lines.*.description_fa' => ['required', 'string', 'max:4000', $text],
            'lines.*.unit' => ['required', 'string', 'max:16', $text],
            'lines.*.quantity' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/', static function (string $attribute, mixed $value, Closure $fail): void {
                if (preg_match('/[1-9]/', $value) !== 1) {
                    $fail(__('mr.quantity_error'));
                }
            }],
            'lines.*.specification' => ['nullable', 'string', 'max:255'],
            'lines.*.subsystem_id' => ['nullable', 'uuid'],
            'company_id' => ['prohibited'], 'project_id' => ['prohibited'], 'raised_by' => ['prohibited'],
            'state' => ['prohibited'], 'sequence' => ['prohibited'], 'number' => ['prohibited'], 'author_policy_id' => ['prohibited'],
        ], ['lines.*.quantity.regex' => __('mr.quantity_error')])->validate();
        foreach ($data['lines'] as $i => $line) {
            if (! empty($line['subsystem_id']) && ! Subsystem::where('project_id', $project->id)->whereKey($line['subsystem_id'])->exists()) {
                throw ValidationException::withMessages(["lines.$i.subsystem_id" => __('mr.subsystem_error')]);
            }
        }
        $data['lines'] = array_values($data['lines']);

        return $data;
    }
}
