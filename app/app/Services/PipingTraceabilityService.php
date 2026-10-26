<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only contract v1. Reference diagnostics, NEVER demand admission, stock or release eligibility. */
final class PipingTraceabilityService
{
    public function __construct(private readonly PipingCustodyService $custody) {}

    public function report(Project $project, User $actor, ?string $receiptId = null): array
    {
        abort_if($receiptId !== null && ! Str::isUuid($receiptId), 404);

        return $this->custody->scoped($project, $actor, function () use ($project, $actor, $receiptId): array {
            $policy = $this->custody->policyId($project, $actor);
            abort_unless($policy !== null, 403);
            $amendment = DB::table('piping_custody_amendments as a0')->whereColumn('a0.custody_id', 'c.id')
                ->where('a0.project_id', $project->id)->orderByDesc('a0.sequence')->limit(1)
                ->select('a0.id', 'a0.sequence', 'a0.source_kind', 'a0.heat_ref');
            $qc = DB::table('piping_receiving_reviews as q0')->whereColumn('q0.custody_id', 'c.id')
                ->where('q0.project_id', $project->id)->orderByDesc('q0.sequence')->limit(1)
                ->select('q0.id', 'q0.sequence', 'q0.amendment_id', 'q0.observed_result');
            $qa = DB::table('piping_qa_decisions as d0')->whereColumn('d0.custody_id', 'c.id')
                ->where('d0.project_id', $project->id)->orderByDesc('d0.sequence')->limit(1)
                ->select('d0.id', 'd0.sequence', 'd0.amendment_id', 'd0.review_id', 'd0.decision');
            // Explicit CASE, never COALESCE: a hidden current amended source must not fall back to arrival.
            $line = DB::raw('CASE WHEN am.id IS NOT NULL THEN s.source_line_id ELSE c.source_line_id END');
            $order = DB::raw('CASE WHEN am.id IS NOT NULL THEN s.purchase_order_id ELSE c.purchase_order_id END');
            $snapshot = '(CASE WHEN am.id IS NOT NULL THEN s.source_snapshot ELSE c.source_snapshot END)';
            $query = DB::table('piping_custody_records as c')->where('c.project_id', $project->id)
                ->when($receiptId !== null, fn ($q) => $q->where('c.id', $receiptId))
                ->leftJoinLateral($amendment, 'am')
                ->leftJoin('piping_custody_amendment_sources as s', fn (JoinClause $j) => $j->on('s.amendment_id', '=', 'am.id')->on('s.project_id', '=', 'c.project_id'))
                ->leftJoin('purchase_order_items as l', fn (JoinClause $j) => $j->on('l.id', '=', $line)->on('l.project_id', '=', 'c.project_id'))
                ->leftJoin('purchase_orders as p', fn (JoinClause $j) => $j->on('p.id', '=', 'l.purchase_order_id')->on('p.id', '=', $order)->on('p.project_id', '=', 'c.project_id')->where('p.discipline', 'PIPING'))
                ->leftJoin('material_requisition_items as m', fn (JoinClause $j) => $j->on('m.id', '=', 'l.requisition_item_id')->on('m.project_id', '=', 'c.project_id'))
                ->leftJoin('material_requisitions as r', fn (JoinClause $j) => $j->on('r.id', '=', DB::raw('COALESCE(m.requisition_id,p.requisition_id)'))->on('r.project_id', '=', 'c.project_id')->on('r.company_id', '=', 'p.company_id')->where('r.discipline', 'PIPING'))
                // Existing buyer-only issue evidence visibility; no buyer/owner impersonation, no prices/snapshots.
                ->leftJoin('po_mr_allocations as al', fn (JoinClause $j) => $j->on('al.purchase_order_item_id', '=', 'l.id')->on('al.purchase_order_id', '=', 'p.id')->on('al.project_id', '=', 'c.project_id')->on('al.buyer_company_id', '=', 'p.company_id')->where('al.buyer_company_id', $actor->company_id))
                ->leftJoin('po_execution_decisions as ev', fn (JoinClause $j) => $j->on('ev.id', '=', 'al.issue_decision_id')->on('ev.project_id', '=', 'c.project_id')->on('ev.purchase_order_id', '=', 'p.id')->on('ev.buyer_company_id', '=', 'al.buyer_company_id')->where('ev.purpose', 'issue'))
                ->leftJoinLateral($qc, 'qc')->leftJoinLateral($qa, 'qa')
                ->select('c.id', 'c.sequence', 'c.shipment_ref', 'c.quantity', 'c.unit',
                    'am.id as amendment_id', 'am.sequence as amendment_sequence',
                    'p.id as po_id', 'p.number as po_number', 'p.state as po_state', 'p.company_id as buyer_id', 'p.requisition_id as mr_header_ref',
                    'l.id as po_line_id', 'l.line_no as po_line_no', 'l.unit as po_unit', 'l.quantity as po_quantity', 'l.requisition_item_id as mr_line_ref', 'l.material_item_id as material_id', 'l.subsystem_id as scope_id',
                    'r.id as mr_id', 'r.number as mr_number', 'r.state as mr_state', 'm.id as mr_item_id', 'm.requisition_id as mr_item_parent', 'm.line_no as mr_line_no', 'm.unit as mr_unit', 'm.quantity as mr_quantity', 'm.material_item_id as mr_material_id', 'm.subsystem_id as mr_scope_id',
                    'al.id as allocation_id', 'al.requisition_id as allocated_mr_id', 'al.requisition_item_id as allocated_mr_item_id', 'al.quantity as allocated_quantity', 'al.approved_quantity', 'al.unit as allocated_unit', 'al.source_hash as allocated_hash', 'ev.id as event_id', 'ev.source_hash as event_hash',
                    'qc.id as qc_id', 'qc.sequence as qc_sequence', 'qc.amendment_id as qc_amendment_id', 'qc.observed_result as qc_result',
                    'qa.id as qa_id', 'qa.sequence as qa_sequence', 'qa.amendment_id as qa_amendment_id', 'qa.review_id as qa_review_id', 'qa.decision as qa_result')
                ->selectRaw("CASE WHEN am.id IS NOT NULL THEN am.source_kind WHEN c.source_line_id IS NULL THEN 'UNRESOLVED' ELSE 'LINKED' END AS source_kind")
                ->selectRaw("(am.id IS NULL OR am.source_kind='UNRESOLVED' OR s.amendment_id IS NOT NULL) AS basis_available")
                ->selectRaw('CASE WHEN am.id IS NOT NULL THEN am.heat_ref ELSE c.heat_ref END AS heat_ref')
                ->selectRaw("CASE WHEN p.id IS NOT NULL THEN (epcc_piping_source_snapshot(c.project_id,l.id)-'state'-'issued_at') IS DISTINCT FROM ({$snapshot}-'state'-'issued_at') ELSE NULL END AS source_changed")
                ->selectRaw("to_char(statement_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US')||'+00:00' AS observed_at")
                ->orderByDesc('c.sequence')->orderBy('c.id');
            // Every page's ancestry and quality heads come from ONE SELECT snapshot, not N+1 mutable reads.
            $records = $query->paginate(25)->withQueryString();
            abort_if($receiptId !== null && $records->isEmpty(), 404);
            $observedAt = $records->first()->observed_at ?? null;
            $records->setCollection($records->getCollection()->map(fn ($row) => $this->describe($row, $actor)));
            $current = $this->custody->policyId($project, $actor);
            abort_unless($current !== null, 403);
            abort_if($policy !== $current, 409, __('piping_traceability.refresh'));

            return compact('records', 'observedAt', 'receiptId') + [
                'contractVersion' => 1, 'coverage' => 'CALLER_VISIBLE_RECEIPTS_ONLY',
                'demandBasis' => 'NOT_CAPTURED', 'effectiveDemand' => null,
                'controlledPosting' => 'NOT_IMPLEMENTED', 'postedQuantity' => null, 'usableQuantity' => null,
                'legacyStockAssessed' => false,
            ];
        });
    }

    private function describe(object $r, User $actor): array
    {
        $available = (bool) $r->basis_available && ($r->source_kind === 'UNRESOLVED' || $r->po_id !== null);
        $po = $r->po_id !== null && $available;
        $sourceStatus = ! $available ? 'UNAVAILABLE' : (! $po ? 'UNRESOLVED' : ($r->source_changed ? 'CHANGED' : 'LINKED'));
        $mrStatus = 'NOT_ASSESSED';
        $mr = null;
        if ($po) {
            if ($r->mr_header_ref === null && $r->mr_line_ref === null) {
                $mrStatus = 'NOT_LINKED';
            } elseif ($r->mr_id === null || ($r->mr_line_ref !== null && $r->mr_item_id === null)) {
                $mrStatus = 'UNAVAILABLE_OR_UNRESOLVED';
            } elseif ($r->mr_header_ref !== null && $r->mr_id !== $r->mr_header_ref) {
                $mrStatus = 'REFERENCE_CONFLICT';
            } else {
                $mrStatus = $r->mr_header_ref === null ? 'LINE_ONLY' : ($r->mr_line_ref === null ? 'HEADER_ONLY' : 'LINKED');
                $mr = ['id' => $r->mr_id, 'number' => $r->mr_number, 'state' => $r->mr_state,
                    'line_id' => $r->mr_item_id, 'line_no' => $r->mr_line_no];
            }
        }
        $alignment = 'NOT_ASSESSED';
        if ($mrStatus === 'LINKED') {
            $alignment = ($r->mr_unit !== $r->po_unit || ! BigDecimal::of($r->mr_quantity)->isEqualTo($r->po_quantity)
                || ($r->mr_material_id !== $r->material_id)
                || ($r->mr_scope_id !== $r->scope_id)) ? 'DIFFERENCES' : 'NO_DIFFERENCE_IN_CHECKED_FIELDS';
        }
        $issue = 'NOT_ASSESSED';
        if ($po) {
            $issue = $r->buyer_id !== $actor->company_id ? 'VISIBILITY_LIMITED' : 'UNVERIFIED';
            if ($r->buyer_id === $actor->company_id && $r->allocation_id !== null && $r->event_id !== null && $r->allocated_hash === $r->event_hash
                && $r->allocated_mr_id === $r->mr_header_ref && $r->allocated_mr_item_id === $r->mr_line_ref && $r->allocated_unit === $r->po_unit
                && BigDecimal::of($r->allocated_quantity)->isEqualTo($r->po_quantity) && BigDecimal::of($r->approved_quantity)->isGreaterThanOrEqualTo($r->allocated_quantity)) {
                $issue = 'RECORDED_LINE_EVIDENCE';
            }
        }
        $qcBinding = $r->qc_id === null ? 'NONE' : (! $available ? 'UNAVAILABLE_BASIS' : ($r->qc_amendment_id === $r->amendment_id ? 'CURRENT' : 'STALE'));
        $qaBinding = $r->qa_id === null ? 'NONE' : (! $available ? 'UNAVAILABLE_BASIS' : ($qcBinding === 'CURRENT' && $r->qa_amendment_id === $r->amendment_id && $r->qa_review_id === $r->qc_id ? 'CURRENT' : 'STALE'));

        return [
            'receipt' => ['id' => $r->id, 'sequence' => $r->sequence, 'shipment' => $r->shipment_ref, 'quantity' => $r->quantity, 'unit' => $r->unit],
            'basis' => ['status' => ! $available ? 'UNAVAILABLE' : ($r->amendment_id === null ? 'ORIGINAL' : 'AMENDED'), 'amendment_id' => $r->amendment_id, 'sequence' => $r->amendment_sequence, 'heat_ref' => $available ? $r->heat_ref : null],
            'source_status' => $sourceStatus,
            'arrival_unit_alignment' => ! $po ? 'NOT_ASSESSED' : ($r->unit === $r->po_unit ? 'SAME_RECORDED_UNIT' : 'UNIT_DIFFERENCE'),
            'po' => $po ? ['id' => $r->po_id, 'number' => $r->po_number, 'state' => $r->po_state, 'line_id' => $r->po_line_id, 'line_no' => $r->po_line_no] : null,
            'material' => ['status' => ! $po ? 'NOT_ASSESSED' : ($r->material_id === null ? 'UNRESOLVED' : 'RECORDED_ID'), 'id' => $po ? $r->material_id : null],
            'scope' => ['status' => ! $po ? 'NOT_ASSESSED' : ($r->scope_id === null ? 'UNRESOLVED' : 'RECORDED_ID'), 'id' => $po ? $r->scope_id : null],
            'mr_status' => $mrStatus, 'mr' => $mr, 'visible_field_alignment' => $alignment, 'issue_evidence' => $issue,
            'qc' => ['id' => $r->qc_id, 'sequence' => $r->qc_sequence, 'result' => $r->qc_result, 'binding' => $qcBinding],
            'qa' => ['id' => $r->qa_id, 'sequence' => $r->qa_sequence, 'result' => $r->qa_result, 'binding' => $qaBinding],
            'mto_basis' => 'NOT_CAPTURED', 'effective_demand' => null, 'posted_quantity' => null, 'usable_quantity' => null,
        ];
    }
}
