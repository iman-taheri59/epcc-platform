<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ADR-038 step1: original drafter, own buyer, complete prices only; never allocation/approval/issue. */
final class PurchaseOrderPricingService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $scope,
        private readonly ProjectArchiveService $archives,
        private readonly PurchaseOrderAccessService $access,
        private readonly PurchaseOrderPricingHistoryService $history,
    ) {}

    public function canPrice(Project $project, User $actor, PurchaseOrder $order): bool
    {
        return $order->state === 'DRAFT' && $order->company_id === $actor->company_id && $order->drafted_by === $actor->id
            && $order->author_policy_id !== null && $order->requisition_id !== null
            && $this->access->policyId($project, $actor, $order->discipline) !== null;
    }

    public function context(Project $project, User $actor, PurchaseOrder $order): array
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $order): array {
            $canPrice = $this->canPrice($project, $actor, $order);
            $pricingHash = $canPrice ? $this->content($order)->hash : null;
            $pricingHistory = $this->history->forOrder($project->id, $order->id);

            return compact('canPrice', 'pricingHash', 'pricingHistory');
        }, $project->id);
    }

    private function content(PurchaseOrder $order): object
    {
        return DB::selectOne('SELECT epcc_po_execution_content(to_jsonb(p))::text AS snapshot,epcc_po_execution_digest(epcc_po_execution_content(to_jsonb(p))) AS hash FROM purchase_orders p WHERE id=? AND project_id=?', [$order->id, $order->project_id]);
    }

    public function price(Project $project, User $actor, string $orderId, array $input): void
    {
        $this->scope->assertAccess($project, $actor);
        try {
            $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $orderId, $input): void {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['po-entry:'.$project->id]);
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $this->archives->assertWritable($project);
                $order = PurchaseOrder::where('project_id', $project->id)->where('company_id', $actor->company_id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
                abort_unless($this->canPrice($project, $actor, $order), 403);
                Validator::make(['pricing' => $input], [
                    'pricing' => ['array:content_hash,currency,reason,lines'], 'pricing.content_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
                    'pricing.currency' => ['required', 'string', 'regex:/\A[A-Z]{3,8}\z/'], 'pricing.reason' => ['required', 'string', 'max:4000'],
                    'pricing.lines' => ['required', 'array', 'list', 'min:1', 'max:20'], 'pricing.lines.*' => ['array:id,unit_price'],
                    'pricing.lines.*.id' => ['required', 'uuid', 'distinct'], 'pricing.lines.*.unit_price' => ['required', 'string', 'regex:/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/'],
                ])->validate();
                if (trim($input['reason']) === '') {
                    $this->refuse('reason_required');
                }
                $before = $this->content($order);
                if (! hash_equals($before->hash, $input['content_hash'])) {
                    $this->refuse('stale');
                }
                // Lock source too; do not mutate its state, quantity, signatures or timestamps.
                DB::table('material_requisitions')->where('id', $order->requisition_id)->where('project_id', $project->id)->where('company_id', $actor->company_id)->lockForUpdate()->firstOrFail();
                DB::table('material_requisition_items')->where('requisition_id', $order->requisition_id)->orderBy('line_no')->orderBy('id')->sharedLock()->get();
                $source = DB::selectOne('SELECT epcc_po_pricing_source_valid(?,?,?) AS valid', [$order->id, $project->id, $actor->company_id]);
                if (! $source->valid) {
                    $this->refuse('source');
                }
                $all = $order->items()->orderBy('id')->lockForUpdate()->get();
                $replaced = $all->pluck('supersedes_id')->filter()->all();
                $active = $all->whereNotIn('id', $replaced)->keyBy('id');
                $submitted = collect($input['lines'])->keyBy('id');
                if ($active->count() !== $submitted->count() || $active->keys()->diff($submitted->keys())->isNotEmpty()) {
                    $this->refuse('lines');
                }
                $total = BigDecimal::zero();
                foreach ($active as $id => $line) {
                    $total = $total->plus(BigDecimal::of((string) $line->quantity)->multipliedBy($submitted[$id]['unit_price']));
                }
                $total = $total->toScale(2, RoundingMode::HalfUp);
                if ($total->isGreaterThan('9999999999999999.99')) {
                    $this->refuse('overflow');
                }
                foreach ($active as $id => $line) {
                    $line->unit_price = $submitted[$id]['unit_price'];
                    $line->save();
                }
                $order->currency = $input['currency'];
                $order->total_value = (string) $total;
                $order->save();
                $after = $this->content($order);
                if ($after->hash === $before->hash) {
                    $this->refuse('no_change');
                }
                DB::table('po_pricing_revisions')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id, 'buyer_company_id' => $actor->company_id, 'purchase_order_id' => $order->id,
                    'actor_id' => $actor->id, 'policy_id' => $this->access->policyId($project, $actor, $order->discipline), 'actor_name' => $actor->name,
                    'before_hash' => $before->hash, 'content_hash' => $after->hash, 'before_snapshot' => $before->snapshot, 'snapshot' => '{}', 'reason' => trim($input['reason']), 'created_at' => now(),
                ]);
            }), $project->id);
        } catch (QueryException $e) {
            if (preg_match('/PO_PRICING_[A-Z_]+/', $e->getMessage(), $match)) {
                throw ValidationException::withMessages(['pricing' => __('po_pricing.refused').' '.$match[0]]);
            }
            throw $e;
        }
    }

    private function refuse(string $key): never
    {
        throw ValidationException::withMessages(['pricing' => __('po_pricing.'.$key)]);
    }
}
