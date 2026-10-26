<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaterialInspection;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Create recorded observations, never a receipt gate, release, financial approval or PO read delegation. */
final class InspectionEntryService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $scope,
        private readonly ProjectArchiveService $archives,
        private readonly InspectionAccessService $access,
    ) {}

    public function create(Project $project, User $actor, string $orderId, array $input): MaterialInspection
    {
        $this->scope->assertAccess($project, $actor);
        try {
            return $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $orderId, $input): MaterialInspection {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-entry:'.$project->id]);
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $this->archives->assertWritable($project);
                // Original caller RLS only; nomination cannot expose a hidden PO.
                $order = PurchaseOrder::where('project_id', $project->id)->whereKey($orderId)->sharedLock()->firstOrFail();
                $policy = $this->access->policyId($project, $actor, $order->discipline);
                abort_unless($policy !== null, 403);
                $data = Validator::make(['inspection' => $input], [
                    'inspection' => ['array:purchase_order_id,result,stage,inspection_date,findings_en,findings_fa'],
                    'inspection.purchase_order_id' => ['required', 'uuid', Rule::in([$orderId])],
                    'inspection.result' => ['required', 'string', Rule::in(ProcurementState::INSPECTION_ALL)],
                    'inspection.stage' => ['required', 'string', Rule::in(ProcurementState::STAGE_ALL)],
                    'inspection.inspection_date' => ['required', 'string', 'date_format:Y-m-d'],
                    'inspection.findings_en' => ['nullable', 'string', 'max:8000'], 'inspection.findings_fa' => ['nullable', 'string', 'max:8000'],
                ])->validate()['inspection'];
                if ($data['result'] !== ProcurementState::INSPECTION_PASS && trim((string) ($data['findings_en'] ?? '')) === '' && trim((string) ($data['findings_fa'] ?? '')) === '') {
                    throw ValidationException::withMessages(['inspection.findings_en' => __('inspection_entry.findings_required')]);
                }
                // All project participants can already see these quality records. Serialize human allocation on the project.
                $sequence = (int) DB::table('material_inspections')->where('project_id', $project->id)->max('sequence') + 1;
                if ($sequence > 2147483647) {
                    throw ValidationException::withMessages(['inspection' => __('inspection_entry.sequence_exhausted')]);
                }
                $record = new MaterialInspection([
                    'project_id' => $project->id, 'company_id' => $actor->company_id, 'purchase_order_id' => $order->id,
                    'number' => sprintf('%s-IRN-%04d', $project->code, $sequence), 'sequence' => $sequence,
                    'result' => $data['result'], 'stage' => $data['stage'], 'findings_en' => $data['findings_en'] ?? null, 'findings_fa' => $data['findings_fa'] ?? null,
                    'inspected_by' => $actor->id, 'inspected_at' => $data['inspection_date'].' 00:00:00+00',
                ]);
                $record->id = (string) Str::uuid();
                $record->entry_policy_id = $policy;
                $record->save();

                return $record->refresh();
            }), $project->id);
        } catch (QueryException $e) {
            if (preg_match('/INSPECTION_[A-Z_]+/', $e->getMessage(), $match)) {
                throw ValidationException::withMessages(['inspection' => __('inspection_entry.refused').' '.$match[0]]);
            }
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['inspection' => __('inspection_entry.number_conflict')]);
            }
            throw $e;
        }
    }
}
