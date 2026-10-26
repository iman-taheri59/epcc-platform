<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Read-only diagnostics through caller RLS; never impersonates a buyer or resolves commitments. */
final class PurchaseOrderSourceReviewService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ProjectFittingDefinitionService $scope) {}

    public function review(Project $project, User $actor, string $orderId): array
    {
        $this->scope->assertAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $orderId): array {
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($fresh->company->plan?->hasModule('proc') && $fresh->company->plan->allowsPack($project->standards_pack), 403);
            $membership = ProjectMembership::where('project_id', $project->id)->where('user_id', $fresh->id)->firstOrFail();
            $disc = $membership->discipline_scope;
            $base = PurchaseOrder::where('project_id', $project->id)->when($disc !== null, fn ($q) => $q->whereIn('discipline', $disc));
            $order = (clone $base)->whereKey($orderId)->firstOrFail();
            abort_unless($order->requisition_id !== null, 404);
            $source = MaterialRequisition::where('project_id', $project->id)->where('company_id', $order->company_id)->whereKey($order->requisition_id)
                ->when($disc !== null, fn ($q) => $q->whereIn('discipline', $disc))->first();
            $sourceLines = $source ? $source->items()->orderBy('line_no')->orderBy('id')->get() : collect();
            // A hidden MR never becomes readable through an order's visible header reference.
            $sourceIds = $sourceLines->pluck('id')->all();
            $related = (clone $base)->where('company_id', $order->company_id)->where(function ($q) use ($order, $sourceIds): void {
                $q->where('requisition_id', $order->requisition_id);
                if ($sourceIds !== []) {
                    $q->orWhereHas('items', fn ($l) => $l->whereIn('requisition_item_id', $sourceIds));
                }
            })->with(['items' => fn ($q) => $q->orderBy('line_no')->orderBy('id')])->orderByDesc('sequence')->orderBy('id')->paginate(25);
            // Oversight PO reads do not confer the newer buyer-only journal/ledger visibility.
            $evidenceVisible = $fresh->company_id === $order->company_id;
            $events = collect();
            $allocations = collect();
            $recorded = collect();
            if ($evidenceVisible) {
                $ids = $related->pluck('id')->all();
                $events = DB::table('po_execution_decisions')->where('project_id', $project->id)->where('buyer_company_id', $order->company_id)
                    ->whereIn('purchase_order_id', $ids)->where('purpose', 'issue')->orderByDesc('sequence')->get()->keyBy('purchase_order_id');
                $allocations = DB::table('po_mr_allocations')->where('project_id', $project->id)->where('buyer_company_id', $order->company_id)
                    ->where('requisition_id', $order->requisition_id)->whereIn('purchase_order_id', $ids)->get()->groupBy('purchase_order_id');
                $recorded = DB::table('po_mr_allocations as a')->join('purchase_orders as p', 'p.id', '=', 'a.purchase_order_id')
                    ->where('a.project_id', $project->id)->where('a.buyer_company_id', $order->company_id)->where('a.requisition_id', $order->requisition_id)
                    ->when($disc !== null, fn ($q) => $q->whereIn('p.discipline', $disc))
                    ->selectRaw('a.requisition_item_id,a.unit,SUM(a.quantity)::text AS quantity')->groupBy('a.requisition_item_id', 'a.unit')->get()->groupBy('requisition_item_id');
            }
            $rows = $related->getCollection()->map(function ($candidate) use ($order, $events, $allocations, $evidenceVisible): array {
                $potential = $candidate->state !== 'DRAFT' || $candidate->issued_by !== null || $candidate->issued_at !== null;
                $status = 'draft';
                $event = $events->get($candidate->id);
                $ledger = $allocations->get($candidate->id, collect());
                if ($potential) {
                    $status = ! $evidenceVisible ? 'evidence_hidden' : (! $event || ! $event->source_hash ? 'legacy' : 'incomplete');
                    if ($evidenceVisible && $event && $event->source_hash && $this->complete($candidate, $event, $ledger)) {
                        $status = 'recorded';
                    }
                }

                return ['order' => $candidate, 'status' => $status, 'line_only' => $candidate->requisition_id !== $order->requisition_id,
                    'decision' => $event, 'allocations' => $ledger];
            });

            return compact('order', 'source', 'sourceLines', 'related', 'rows', 'evidenceVisible', 'recorded') + ['disciplineLimited' => $disc !== null, 'generatedAt' => now()->toIso8601String()];
        }, $project->id);
    }

    private function complete(PurchaseOrder $order, object $event, $ledger): bool
    {
        $replaced = $order->items->pluck('supersedes_id')->filter()->all();
        $active = $order->items->whereNotIn('id', $replaced);
        if ($active->isEmpty() || $active->count() !== $ledger->count()) {
            return false;
        }
        $byLine = $ledger->keyBy('purchase_order_item_id');

        return $active->every(function ($line) use ($order, $event, $byLine): bool {
            $a = $byLine->get($line->id);

            return $a && $a->issue_decision_id === $event->id && $a->source_hash === $event->source_hash
                && $a->purchase_order_id === $order->id && $a->requisition_id === $order->requisition_id
                && $a->requisition_item_id === $line->requisition_item_id && $a->unit === $line->unit
                && BigDecimal::of($a->quantity)->isEqualTo((string) $line->quantity)
                && BigDecimal::of($a->approved_quantity)->isGreaterThanOrEqualTo($a->quantity);
        });
    }
}
