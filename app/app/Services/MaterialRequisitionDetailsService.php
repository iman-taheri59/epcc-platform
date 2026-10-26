<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Read-only MR sheet; original caller RLS only, no journal/actor-directory delegation. */
final class MaterialRequisitionDetailsService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ProjectFittingDefinitionService $scope, private readonly PurchaseOrderAccessService $poAccess) {}

    public function read(Project $project, User $actor, string $requisitionId): array
    {
        $this->scope->assertAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $requisitionId): array {
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($fresh->company->plan?->hasModule('proc') && $fresh->company->plan->allowsPack($project->standards_pack), 403);
            $membership = ProjectMembership::where('project_id', $project->id)->where('user_id', $fresh->id)->firstOrFail();
            $disciplines = $membership->discipline_scope;
            $requisition = MaterialRequisition::where('project_id', $project->id)->whereKey($requisitionId)
                ->when($disciplines !== null, fn ($q) => $q->whereIn('discipline', $disciplines))
                ->with(['company', 'items' => fn ($q) => $q->where('project_id', $project->id)->orderBy('line_no')->orderBy('id'),
                    'items.subsystem' => fn ($q) => $q->where('project_id', $project->id)])->firstOrFail();
            $sourceIds = $requisition->items->pluck('id')->all();
            $related = PurchaseOrder::where('project_id', $project->id)->where('company_id', $requisition->company_id)
                ->when($disciplines !== null, fn ($q) => $q->whereIn('discipline', $disciplines))
                ->where(function ($q) use ($requisition, $sourceIds): void {
                    $q->where('requisition_id', $requisition->id);
                    if ($sourceIds !== []) {
                        $q->orWhereHas('items', fn ($l) => $l->where('project_id', $requisition->project_id)->whereIn('requisition_item_id', $sourceIds));
                    }
                })->orderByDesc('sequence')->orderBy('id')->paginate(25, ['*'], 'orders_page');

            // Presentation only: the existing POST service rechecks all live gates under locks.
            $canRaisePo = $requisition->company_id === $fresh->company_id && $requisition->isOrderable()
                && $requisition->material_category !== null && $requisition->items->count() >= 1 && $requisition->items->count() <= 20
                && $this->poAccess->allows($project, $fresh, $requisition->discipline);
            $vendors = $canRaisePo ? Company::where('tenant_type', 'VEN')
                ->whereIn('id', DB::table('project_company')->where('project_id', $project->id)->select('company_id'))
                ->orderBy('code')->get() : collect();

            return compact('requisition', 'related', 'canRaisePo', 'vendors') + ['disciplineLimited' => $disciplines !== null];
        }, $project->id);
    }
}
