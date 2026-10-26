<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ADR-036/037: independent scoped nominations + immutable version decisions + 3-person separation. */
final class PurchaseOrderExecutionService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $scope,
        private readonly ProjectArchiveService $archives,
        private readonly PurchaseOrderExecutionAccessService $access,
    ) {}

    private function assertBuyerGrant(Project $project, User $actor, string $buyerId, ?string $discipline = null): void
    {
        abort_unless($this->access->nominationId($project, $actor, $buyerId, 'finance', $discipline)
            || $this->access->nominationId($project, $actor, $buyerId, 'issue', $discipline), 403);
    }

    /** Delegation is limited to this PO commercial workspace, never the generic detail/warehouse/MR routes. */
    public function workspace(Project $project, User $actor, string $buyerId, ?string $orderId = null, ?string $category = null): array
    {
        $this->scope->assertAccess($project, $actor);
        $this->assertBuyerGrant($project, $actor, $buyerId);
        $membership = $this->tenant->runAs($actor->company_id, fn () => ProjectMembership::where('project_id', $project->id)->where('user_id', $actor->id)->firstOrFail(), $project->id);

        $canReviewSource = $orderId ? $this->tenant->runAs($actor->company_id, fn () => PurchaseOrder::where('project_id', $project->id)->whereKey($orderId)->whereNotNull('requisition_id')
            ->when($membership->discipline_scope !== null, fn ($q) => $q->whereIn('discipline', $membership->discipline_scope))->exists(), $project->id) : false;

        return $this->tenant->runAs($buyerId, function () use ($project, $actor, $buyerId, $orderId, $membership, $category, $canReviewSource): array {
            $query = PurchaseOrder::where('project_id', $project->id)->where('company_id', $buyerId)->whereNotNull('author_policy_id');
            if ($membership->discipline_scope !== null) {
                $query->whereIn('discipline', $membership->discipline_scope);
            }
            $order = $orderId ? (clone $query)->whereKey($orderId)->with(['items' => fn ($q) => $q->orderBy('line_no')->orderBy('id'), 'vendor'])->firstOrFail() : null;
            $categories = MaterialItem::categories();
            $category = $order?->material_category ?? $category ?? 'PIPE';
            abort_unless(in_array($category, $categories, true), 422);
            $orders = (clone $query)->where('material_category', $category)->orderByDesc('sequence')->paginate(20)->withQueryString();
            $history = collect();
            $hash = null;
            $finance = null;
            $canFinance = false;
            $canIssue = false;
            $sourceRefusal = null;
            $issueRefusal = null;
            $sourceHash = null;
            $allocations = collect();
            if ($order) {
                $this->assertBuyerGrant($project, $actor, $buyerId, $order->discipline);
                $hash = $this->hash($order);
                $history = DB::table('po_execution_decisions')->where('purchase_order_id', $order->id)->where('project_id', $project->id)->orderByDesc('sequence')->limit(50)->get();
                $finance = DB::table('po_execution_decisions')->where('purchase_order_id', $order->id)->where('purpose', 'finance')->orderByDesc('sequence')->first();
                if ($order->requisition_id !== null) {
                    $sourceHash = DB::selectOne('SELECT epcc_po_mr_source_hash(?) AS hash', [$order->requisition_id])->hash;
                    if ($order->state === 'DRAFT') {
                        $sourceRefusal = DB::selectOne('SELECT epcc_po_mr_refusal(?,false) AS reason', [$order->id])->reason;
                        $issueRefusal = DB::selectOne('SELECT epcc_po_mr_refusal(?,true) AS reason', [$order->id])->reason;
                    }
                    $allocations = DB::table('po_mr_allocations')->where('project_id', $project->id)->where('buyer_company_id', $buyerId)->where('purchase_order_id', $order->id)->orderBy('requisition_item_id')->get();
                }
                $eligible = $sourceRefusal === null && $order->total_value !== null && $order->currency !== null && $order->drafted_by !== null;
                $canFinance = $eligible && $order->state === 'DRAFT' && $order->drafted_by !== $actor->id && $this->access->nominationId($project, $actor, $buyerId, 'finance', $order->discipline) !== null;
                $financeLive = $finance ? DB::selectOne('SELECT epcc_po_execution_live_policy(?,?,?,?,?,?) AS id', [$project->id, $finance->actor_id, $finance->actor_company_id, $buyerId, 'finance', $order->discipline])->id : null;
                $canIssue = $eligible && $issueRefusal === null && $order->state === 'DRAFT' && $order->drafted_by !== $actor->id && $finance && $finance->actor_id !== $actor->id
                    && $finance->content_hash === $hash && $finance->source_hash === $sourceHash && $finance->policy_id === $financeLive && $this->access->nominationId($project, $actor, $buyerId, 'issue', $order->discipline) !== null;
            }

            return compact('orders', 'order', 'history', 'hash', 'finance', 'canFinance', 'canIssue', 'category', 'categories', 'sourceRefusal', 'issueRefusal', 'sourceHash', 'allocations', 'canReviewSource') + ['buyer' => Company::findOrFail($buyerId)];
        }, $project->id);
    }

    public function hash(PurchaseOrder $order): string
    {
        return DB::selectOne('SELECT epcc_po_execution_digest(epcc_po_execution_content(to_jsonb(p))) AS hash FROM purchase_orders p WHERE id=? AND project_id=?', [$order->id, $order->project_id])->hash;
    }

    public function act(Project $project, User $actor, string $buyerId, string $orderId, string $purpose, array $input): void
    {
        abort_unless(in_array($purpose, ['finance', 'issue'], true), 404);
        $this->scope->assertAccess($project, $actor);
        try {
            $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $buyerId, $orderId, $purpose, $input): void {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['po-entry:'.$project->id]);
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $this->archives->assertWritable($project);
                abort_unless($this->access->nominationId($project, $actor, $buyerId, $purpose) !== null, 403);
                Validator::make(['execution' => $input], [
                    'execution' => ['array:content_hash,reason,approval_id'], 'execution.content_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
                    'execution.reason' => ['required', 'string', 'max:4000'],
                    'execution.approval_id' => $purpose === 'issue' ? ['required', 'uuid'] : ['prohibited'],
                ])->validate();
                if (trim($input['reason']) === '') {
                    throw ValidationException::withMessages(['execution.reason' => __('po_execution.reason_required')]);
                }
                $this->tenant->runAs($buyerId, fn () => DB::transaction(function () use ($project, $actor, $buyerId, $orderId, $purpose, $input): void {
                    $order = PurchaseOrder::where('project_id', $project->id)->where('company_id', $buyerId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
                    $policy = $this->access->nominationId($project, $actor, $buyerId, $purpose, $order->discipline);
                    abort_unless($policy !== null && $order->drafted_by !== $actor->id, 403);
                    if ($purpose === 'issue') {
                        $approval = DB::table('po_execution_decisions')->where('purchase_order_id', $order->id)->where('purpose', 'finance')->orderByDesc('sequence')->first();
                        abort_if($approval && $approval->actor_id === $actor->id, 403);
                    }
                    DB::table('po_execution_decisions')->insert([
                        'id' => (string) Str::uuid(), 'project_id' => $project->id, 'buyer_company_id' => $buyerId, 'purchase_order_id' => $order->id,
                        'policy_id' => $policy, 'actor_id' => $actor->id, 'actor_company_id' => $actor->company_id, 'actor_name' => $actor->name,
                        'purpose' => $purpose, 'approval_id' => $purpose === 'issue' ? $input['approval_id'] : null, 'content_hash' => $input['content_hash'],
                        'snapshot' => '{}', 'reason' => trim($input['reason']), 'created_at' => now(),
                    ]);
                    if ($purpose === 'issue') {
                        $order->state = 'ISSUED';
                        $order->save();
                    }
                }), $project->id);
            }), $project->id);
        } catch (QueryException $e) {
            if (preg_match('/PO_EXEC_[A-Z_]+/', $e->getMessage(), $match)) {
                throw ValidationException::withMessages(['execution' => __('po_execution.refused').' '.$match[0]]);
            }
            throw $e;
        }
    }
}
