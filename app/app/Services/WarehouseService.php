<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\InspectionReleaseNote;
use App\Models\MaterialLot;
use App\Models\MaterialMovement;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\MovementType;
use App\Support\ProcurementState;
use App\Support\TenantType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * بازرسی، ورود به انبار، خروج از انبار، ورود به سایت.
 *
 * Everything physical happens here. The ledger is append-only: there is no
 * update and no delete, and a mistake is corrected with a reversing movement.
 * That is not bureaucratic purity - a warehouse's only real asset is being
 * able to say what it held on the day somebody audits it, and a mutable
 * ledger cannot answer that question at all.
 *
 * WHAT IS ENFORCED, AND WHAT DELIBERATELY IS NOT
 *
 *   enforced      you cannot issue more than the warehouse holds
 *   enforced      you cannot deliver more to site than was issued
 *   enforced      a receipt needs an issued purchase order
 *   NOT enforced  inspection. The user chose advisory: a failed or missing
 *                 inspection records a warning and lets the receipt through.
 *
 * The last one is a real decision with a real cost. Material lands at 2am and
 * the TPI signs on Sunday; a system that refuses the receipt gets bypassed
 * with a paper note that never gets typed in, and then the warehouse module
 * is a fiction. The price is that "received but not passed" must be loudly
 * visible - which is why inspection_result is denormalised onto the lot and
 * surfaced by uninspectedLots() rather than left in a report nobody runs.
 */
final class WarehouseService
{
    /** Company types whose staff may sign an inspection. */
    public const INSPECTOR_TYPES = [
        TenantType::TPI, TenantType::PMC, TenantType::MC, TenantType::OWN,
    ];

    /**
     * ADR-047 cutover gate. While epcc.warehouse.legacy_writes_refused is on,
     * this legacy core accepts NO stock writes at all — fail-closed, before
     * any other validation, so a closed warehouse can never be half-used.
     * Reading history (subsystemMaterialStatus, uninspectedLots) stays open:
     * the legacy record remains evidence even after it stops being a writer.
     */
    private function assertLegacyWritesOpen(string $lang): void
    {
        if ((bool) config('epcc.warehouse.legacy_writes_refused', false)) {
            throw ValidationException::withMessages(['warehouse' => $lang === 'fa'
                ? 'هستهٔ انبار قدیمی بسته شده است (ADR-047): عملیات موجودی فقط از طریق دفتر موجودی یکپارچه و مسیر GRN کنترل‌شده انجام می‌شود.'
                : 'The legacy warehouse core is closed (ADR-047): stock operations go through the unified stock ledger and the controlled GRN path only.']);
        }
    }

    // -----------------------------------------------------------------------
    // بازرسی - inspection
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // ورود به انبار - goods receipt
    // -----------------------------------------------------------------------

    /**
     * Receive material into the warehouse, creating the lot and its first
     * movement in one transaction.
     *
     * @throws ValidationException
     */
    public function receive(
        Project $project,
        User $user,
        PurchaseOrder $po,
        string $itemCode,
        string $descriptionEn,
        string $descriptionFa,
        string $unit,
        float $quantity,
        ?string $heatNumber = null,
        ?string $serialNumber = null,
        ?string $location = null,
        ?InspectionReleaseNote $releaseNote = null,
        ?string $mtcDocumentId = null,
        ?Subsystem $subsystem = null,
        string $lang = 'fa',
    ): MaterialLot {
        $this->assertLegacyWritesOpen($lang);
        // Known PIPING receipts now belong exclusively to the controlled QA/GRN ledger.
        if (PurchaseOrder::whereKey($po->id)->where('project_id', $project->id)->value('discipline') === 'PIPING') {
            throw ValidationException::withMessages(['purchase_order' => __('piping_grn.blocked')]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => $lang === 'fa' ? 'مقدار باید بزرگ‌تر از صفر باشد.' : 'Quantity must be greater than zero.',
            ]);
        }

        /*
         * A receipt needs a live order. Receiving against a draft PO means
         * material arrived for something nobody ordered, and against a
         * cancelled one means it arrived for something we told the vendor to
         * stop. Both happen, and both need a human decision rather than a
         * silent stock increase.
         */
        if (! $po->acceptsReceipts()) {
            throw ValidationException::withMessages([
                'purchase_order' => $lang === 'fa'
                    ? 'فقط روی سفارش خرید صادرشده می‌توان کالا تحویل گرفت.'
                    : 'Goods can only be received against an issued purchase order.',
            ]);
        }

        return DB::transaction(function () use (
            $project, $user, $po, $itemCode, $descriptionEn, $descriptionFa,
            $unit, $quantity, $heatNumber, $serialNumber, $location,
            $releaseNote, $mtcDocumentId, $subsystem
        ): MaterialLot {
            Project::whereKey($project->id)->lockForUpdate()->first();

            $lotSeq = 1 + (int) MaterialLot::where('project_id', $project->id)->count();

            $lot = MaterialLot::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'purchase_order_id' => $po->id,
                'subsystem_id' => $subsystem?->id,
                'lot_code' => sprintf('%s-LOT-%04d', $project->code, $lotSeq),
                'item_code' => $itemCode,
                'description_en' => $descriptionEn,
                'description_fa' => $descriptionFa,
                'unit' => $unit,
                'heat_number' => $heatNumber,
                'serial_number' => $serialNumber,
                'mtc_document_id' => $mtcDocumentId,
                'release_note_id' => $releaseNote?->id,
                'inspection_result' => $releaseNote?->verdict(),
            ]);

            $this->post(
                $project, $user, $lot, MovementType::RECEIPT, $quantity,
                toLocation: $location,
                subsystem: null,
            );

            $this->refreshPurchaseOrderState($po);

            return $lot->refresh();
        });
    }

    // -----------------------------------------------------------------------
    // خروج از انبار - issue
    // -----------------------------------------------------------------------

    /** @throws ValidationException */
    public function issue(
        Project $project,
        User $user,
        MaterialLot $lot,
        float $quantity,
        ?Subsystem $subsystem = null,
        ?string $toLocation = null,
        ?string $note = null,
        string $lang = 'fa',
    ): MaterialMovement {
        $this->assertLegacyWritesOpen($lang);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => $lang === 'fa' ? 'مقدار باید بزرگ‌تر از صفر باشد.' : 'Quantity must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($project, $user, $lot, $quantity, $subsystem, $toLocation, $note, $lang): MaterialMovement {
            /*
             * Lock the lot before reading the balance. Without this, two
             * storekeepers issuing the last 40 m of a lot at the same moment
             * both read 40 available and both succeed, and the ledger goes
             * negative. This is the classic read-then-write race and a
             * warehouse hits it every single day at shift change.
             */
            MaterialLot::whereKey($lot->id)->lockForUpdate()->first();

            $available = $lot->fresh()->balance();

            if ($quantity > $available + 1e-9) {
                throw ValidationException::withMessages([
                    'quantity' => $lang === 'fa'
                        ? sprintf('موجودی کافی نیست: %s %s در انبار است.', rtrim(rtrim(number_format($available, 3), '0'), '.'), $lot->unit)
                        : sprintf('Not enough stock: the warehouse holds %s %s.', rtrim(rtrim(number_format($available, 3), '0'), '.'), $lot->unit),
                ]);
            }

            return $this->post(
                $project, $user, $lot, MovementType::ISSUE, $quantity,
                fromLocation: null,
                toLocation: $toLocation,
                subsystem: $subsystem,
                note: $note,
            );
        });
    }

    // -----------------------------------------------------------------------
    // ورود به سایت - site delivery
    // -----------------------------------------------------------------------

    /**
     * Record that issued material physically reached the subsystem.
     *
     * @throws ValidationException
     */
    public function deliverToSite(
        Project $project,
        User $user,
        MaterialLot $lot,
        Subsystem $subsystem,
        float $quantity,
        ?string $note = null,
        string $lang = 'fa',
    ): MaterialMovement {
        $this->assertLegacyWritesOpen($lang);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => $lang === 'fa' ? 'مقدار باید بزرگ‌تر از صفر باشد.' : 'Quantity must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($project, $user, $lot, $subsystem, $quantity, $note, $lang): MaterialMovement {
            MaterialLot::whereKey($lot->id)->lockForUpdate()->first();

            /*
             * You cannot deliver to site more than left the warehouse. Without
             * this the two steps stop reconciling and the "in transit" figure
             * - which is the number a site manager chases - goes negative and
             * becomes meaningless.
             */
            $movements = $lot->fresh()->movements()->get();

            $issued = 0.0;
            $delivered = 0.0;

            foreach ($movements as $m) {
                if ($m->type === MovementType::ISSUE) {
                    $issued += (float) $m->quantity;
                }
                if ($m->type === MovementType::SITE) {
                    $delivered += (float) $m->quantity;
                }
            }

            $inTransit = $issued - $delivered;

            if ($quantity > $inTransit + 1e-9) {
                throw ValidationException::withMessages([
                    'quantity' => $lang === 'fa'
                        ? sprintf('بیش از مقدار خروجی از انبار قابل تحویل به سایت نیست: %s %s در راه است.', rtrim(rtrim(number_format($inTransit, 3), '0'), '.'), $lot->unit)
                        : sprintf('Cannot deliver more than was issued: %s %s is in transit.', rtrim(rtrim(number_format($inTransit, 3), '0'), '.'), $lot->unit),
                ]);
            }

            return $this->post(
                $project, $user, $lot, MovementType::SITE, $quantity,
                subsystem: $subsystem,
                note: $note,
            );
        });
    }

    /** Material came back unused. */
    public function returnToStore(
        Project $project,
        User $user,
        MaterialLot $lot,
        float $quantity,
        ?string $note = null,
        string $lang = 'fa',
    ): MaterialMovement {
        $this->assertLegacyWritesOpen($lang);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => $lang === 'fa' ? 'مقدار باید بزرگ‌تر از صفر باشد.' : 'Quantity must be greater than zero.',
            ]);
        }

        return $this->post($project, $user, $lot, MovementType::RETURN, $quantity, note: $note);
    }

    /**
     * Correct a mistake by reversing it.
     *
     * The original row is never touched. Two movements that cancel out is the
     * truthful record of what happened: somebody booked it, then somebody
     * un-booked it, and both facts are part of the audit.
     *
     * @throws ValidationException
     */
    public function reverse(MaterialMovement $movement, User $user, string $reason, string $lang = 'fa'): MaterialMovement
    {
        $this->assertLegacyWritesOpen($lang);
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => $lang === 'fa' ? 'دلیل ابطال الزامی است.' : 'A reversal must record a reason.',
            ]);
        }

        if ($movement->isReversal()) {
            throw ValidationException::withMessages([
                'movement' => $lang === 'fa'
                    ? 'یک سند ابطال را نمی‌توان دوباره ابطال کرد.'
                    : 'A reversing movement cannot itself be reversed.',
            ]);
        }

        $already = MaterialMovement::where('reverses_movement_id', $movement->id)->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'movement' => $lang === 'fa'
                    ? 'این سند قبلاً ابطال شده است.'
                    : 'That movement has already been reversed.',
            ]);
        }

        $project = Project::findOrFail($movement->project_id);
        $lot = MaterialLot::findOrFail($movement->lot_id);

        /*
         * The reversal carries the OPPOSITE type, not a negative quantity.
         * Quantities stay positive everywhere in this module; meaning is the
         * type's job. A reversed issue is a return, a reversed receipt is an
         * issue back out.
         */
        $opposite = match ($movement->type) {
            MovementType::RECEIPT => MovementType::ISSUE,
            MovementType::ISSUE => MovementType::RETURN,
            MovementType::RETURN => MovementType::ISSUE,
            MovementType::SITE => MovementType::SITE,
            default => MovementType::ISSUE,
        };

        return $this->post(
            $project, $user, $lot, $opposite, (float) $movement->quantity,
            subsystem: $movement->subsystem_id !== null ? Subsystem::find($movement->subsystem_id) : null,
            note: $reason,
            reverses: $movement,
        );
    }

    // -----------------------------------------------------------------------
    // Reporting - the MC link the user asked for
    // -----------------------------------------------------------------------

    /**
     * Material shortage for a subsystem: ordered vs actually delivered to site.
     *
     * This is the number the MC gate needs. A subsystem whose valves are still
     * in the warehouse is not mechanically complete however few punch items
     * it has, and before this module that fact lived in somebody's
     * spreadsheet.
     *
     * Computed live, never stored. Same rule as closurePercent().
     *
     * @return array{ordered:float,delivered:float,shortfall:float,lines:list<array<string,mixed>>,complete:bool}
     */
    public function subsystemMaterialStatus(Subsystem $subsystem): array
    {
        $orderedLines = \App\Models\PurchaseOrderItem::query()
            ->where('project_id', $subsystem->project_id)
            ->where('subsystem_id', $subsystem->id)
            ->get();

        $lines = [];
        $totalOrdered = 0.0;
        $totalDelivered = 0.0;

        foreach ($orderedLines as $line) {
            $ordered = (float) $line->quantity;

            // Delivered to site for this item code on this subsystem.
            /*
             * Scoped to the project as well as the item code. RLS would
             * usually catch a cross-project read, but a report that depends
             * on RLS for its ARITHMETIC is one migration away from being
             * quietly wrong - and a wrong shortage figure at an MC gate is
             * the kind of error nobody catches until handover.
             */
            $delivered = (float) MaterialMovement::query()
                ->where('project_id', $subsystem->project_id)
                ->where('subsystem_id', $subsystem->id)
                ->where('type', MovementType::SITE)
                ->whereIn('lot_id', MaterialLot::query()
                    ->where('project_id', $subsystem->project_id)
                    ->where('item_code', $line->item_code)
                    ->select('id'))
                ->sum('quantity');

            $shortfall = max(0.0, $ordered - $delivered);

            $totalOrdered += $ordered;
            $totalDelivered += min($delivered, $ordered);

            $lines[] = [
                'item_code' => $line->item_code,
                'description_en' => $line->description_en,
                'description_fa' => $line->description_fa,
                'unit' => $line->unit,
                'ordered' => round($ordered, 3),
                'delivered' => round($delivered, 3),
                'shortfall' => round($shortfall, 3),
            ];
        }

        return [
            'ordered' => round($totalOrdered, 3),
            'delivered' => round($totalDelivered, 3),
            'shortfall' => round(max(0.0, $totalOrdered - $totalDelivered), 3),
            'lines' => $lines,
            /*
             * A subsystem with NO material lines is "complete" for material
             * purposes. Same call as closurePercent() returning 100 for an
             * empty punch list: absence of a requirement is not a shortage,
             * and reporting it as one would flag every civil subsystem that
             * buys nothing.
             */
            'complete' => $totalOrdered <= 0.0 || $totalDelivered >= $totalOrdered - 1e-9,
        ];
    }

    /**
     * Lots that arrived without a clean inspection. This is the visibility
     * that pays for the advisory choice - without it, "advisory" is just
     * "ignored".
     *
     * @return \Illuminate\Support\Collection<int,MaterialLot>
     */
    public function uninspectedLots(Project $project)
    {
        return MaterialLot::where('project_id', $project->id)
            ->where(function ($q): void {
                $q->whereNull('inspection_result')
                    ->orWhere('inspection_result', '!=', ProcurementState::INSPECTION_PASS);
            })
            ->orderBy('lot_code')
            ->get();
    }

    // -----------------------------------------------------------------------

    /**
     * The single writer for the ledger. Every movement in the system comes
     * through here, so the numbering, the locking and the append-only rule
     * are stated once.
     */
    private function post(
        Project $project,
        User $user,
        MaterialLot $lot,
        string $type,
        float $quantity,
        ?string $fromLocation = null,
        ?string $toLocation = null,
        ?Subsystem $subsystem = null,
        ?string $note = null,
        ?MaterialMovement $reverses = null,
    ): MaterialMovement {
        $seq = 1 + (int) MaterialMovement::where('project_id', $project->id)->max('sequence');

        return MaterialMovement::create([
            'project_id' => $project->id,
            'company_id' => $user->company_id,
            'lot_id' => $lot->id,
            'number' => sprintf('%s-%s-%04d', $project->code, MovementType::prefix($type), $seq),
            'sequence' => $seq,
            'type' => $type,
            'quantity' => $quantity,
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
            'subsystem_id' => $subsystem?->id,
            'note' => $note,
            'performed_by' => $user->id,
            'performed_at' => now(),
            'reverses_movement_id' => $reverses?->id,
        ]);
    }

    /**
     * Recompute the PO state from what has actually been received.
     *
     * Derived and re-set rather than nudged: a state machine that is advanced
     * by hand drifts from the ledger the first time somebody reverses a
     * receipt.
     */
    private function refreshPurchaseOrderState(PurchaseOrder $po): void
    {
        $ordered = (float) $po->items()->sum('quantity');

        if ($ordered <= 0.0) {
            return;
        }

        $received = (float) MaterialMovement::query()
            ->where('type', MovementType::RECEIPT)
            ->whereIn('lot_id', MaterialLot::where('purchase_order_id', $po->id)->select('id'))
            ->sum('quantity');

        $next = match (true) {
            $received >= $ordered - 1e-9 => ProcurementState::PO_RECEIVED,
            $received > 0.0 => ProcurementState::PO_PARTIALLY_RECEIVED,
            default => $po->state,
        };

        if ($next !== $po->state && ProcurementState::poCanTransition($po->state, $next)) {
            $po->update(['state' => $next]);
        }
    }
}
