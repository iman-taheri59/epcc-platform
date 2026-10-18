<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 (MTO Slice 6) — PO attribution, amendment lineage and scope
 * allocation, the inputs of the order gap.
 *
 * Three shapes, each demanded by an accepted domain rule:
 *
 * 1. A PO line carries a `material_item_id` FK — exact catalogue identity.
 *    A line whose material cannot be resolved stays NULL and lands in the
 *    unmatched bucket; identity is never guessed.
 *
 * 2. An amendment never mutates. It creates a NEW line pointing at the
 *    replaced one (`supersedes_id`). The partial unique index means a line
 *    is superseded at most once, and the gap computation reads lineage heads
 *    only. Superseded rows remain readable — deletion is not the mechanism.
 *
 * 3. Scope allocation is explicit rows (`po_item_allocations`), never a
 *    proration. The service refuses an allocation that would exceed the item
 *    quantity; the remainder is `unallocated` and shown as such.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $t): void {
            $t->uuid('material_item_id')->nullable()->after('subsystem_id');
            $t->uuid('supersedes_id')->nullable()->after('quantity');

            $t->foreign('material_item_id')->references('id')->on('material_items')->nullOnDelete();
            $t->foreign('supersedes_id')->references('id')->on('purchase_order_items')->nullOnDelete();

            // A line is replaced at most once; the head of a lineage is the
            // row nobody points at. NULLs never conflict in PostgreSQL.
            $t->unique('supersedes_id');
            $t->index(['purchase_order_id', 'material_item_id']);
        });

        Schema::create('po_item_allocations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('purchase_order_item_id');
            $t->uuid('subsystem_id');
            $t->decimal('quantity', 18, 3);
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->cascadeOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // One allocation per (item, subsystem); a change is an update of
            // that row by the service, never a duplicate entry.
            $t->unique(['purchase_order_item_id', 'subsystem_id']);
            $t->index(['project_id', 'subsystem_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE po_item_allocations ADD CONSTRAINT po_item_allocations_quantity_positive
                CHECK (quantity > 0);

            ALTER TABLE po_item_allocations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE po_item_allocations FORCE ROW LEVEL SECURITY;

            CREATE POLICY po_item_allocations_tenant_isolation ON po_item_allocations
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR po_item_allocations.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = po_item_allocations.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = po_item_allocations.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM purchase_order_items poi
                    WHERE poi.id = po_item_allocations.purchase_order_item_id
                      AND poi.project_id = po_item_allocations.project_id
                )
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS po_item_allocations_tenant_isolation ON po_item_allocations;
        SQL);

        Schema::dropIfExists('po_item_allocations');

        Schema::table('purchase_order_items', function (Blueprint $t): void {
            $t->dropForeign(['material_item_id']);
            $t->dropForeign(['supersedes_id']);
            $t->dropUnique(['supersedes_id']);
            $t->dropIndex(['purchase_order_id', 'material_item_id']);
            $t->dropColumn(['material_item_id', 'supersedes_id']);
        });
    }
};
