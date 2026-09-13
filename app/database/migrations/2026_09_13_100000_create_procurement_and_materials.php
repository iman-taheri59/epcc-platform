<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROCUREMENT and MATERIALS - the second C in EPCC.
 *
 * The physical chain, as the user described it:
 *
 *   تهیه          MR -> approvals -> PO -> expediting
 *   بازرسی        inspection at the vendor's works or on arrival
 *   ورود به انبار  goods receipt (GRN) - stock exists from here on
 *   خروج از انبار  issue (MIV) - stock leaves the warehouse
 *   ورود به سایت   site delivery (SDN) - it reaches the subsystem
 *
 * ---------------------------------------------------------------------------
 * WHY MOVEMENTS ARE ONE TABLE AND NOT FOUR
 * ---------------------------------------------------------------------------
 * GRN, MIV, SDN and the return note are four different pieces of paper, but
 * they are all the same fact: a quantity of one lot moved from somewhere to
 * somewhere at a time, and somebody signed for it. Modelling them as four
 * tables would mean four sequence allocators, four RLS policies, and a stock
 * balance that has to UNION four shapes and stay correct as a fifth is added.
 *
 * The ledger is append-only. A wrong receipt is corrected with a reversing
 * movement, never an UPDATE - the warehouse's credibility rests on being able
 * to say what the stock was on the day of an audit, and an editable ledger
 * cannot answer that. Same reasoning as the certificate punch-summary
 * snapshot.
 *
 * ---------------------------------------------------------------------------
 * WHY LOTS EXIST
 * ---------------------------------------------------------------------------
 * The user asked for traceability down to heat number and material
 * certificate. In an oil and gas project that is not a nicety: a TPI signing
 * off a pressure-containing weld must be able to name the heat the pipe came
 * from and produce its MTC. So stock is not "300 m of 6 inch pipe", it is
 * "120 m of heat A-4471 and 180 m of heat A-4472", and every issue draws from
 * a named lot.
 *
 * The MTC itself is NOT a new file store. It is a document in the CDE we
 * already have, so it inherits revisions, transmittals, review and access
 * control. A material certificate that lives outside the document register is
 * a material certificate nobody can find during a handover audit.
 *
 * ---------------------------------------------------------------------------
 * RLS: THIS MODULE IS COMMERCIALLY SENSITIVE, UNLIKE THE PUNCH LIST
 * ---------------------------------------------------------------------------
 * A punch list is shared - the whole project walks down together. Procurement
 * is not. Two rules that are easy to get wrong:
 *
 *   - A VENDOR must see the PO addressed to it and nothing else. Vendors on a
 *     project are frequently competitors; showing VEN-AZAR the prices in
 *     another vendor's PO would be a commercial incident.
 *   - Requisitions are the buyer's own planning. The employer and the contract
 *     administrator (PMC/MC) see them because they approve them; vendors never
 *     do.
 *
 * Stock and movements are operational rather than commercial, so participants
 * may read them - the employer needs to know whether the valve is on site -
 * but only the custodian writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // 1. Requisition: "we need this material"
        // -------------------------------------------------------------------
        Schema::create('material_requisitions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The requesting company - normally the EPC contractor.
            $t->uuid('company_id');

            $t->string('number', 64);
            $t->unsignedInteger('sequence');
            $t->string('state', 24); // MaterialRequisitionState

            $t->string('title_en');
            $t->string('title_fa');
            $t->string('discipline', 32);

            $t->uuid('raised_by');
            $t->timestampTz('raised_at');

            /*
             * Technical and commercial approval are SEPARATE columns, not one
             * "approved_by". On a real MR the engineer confirms the spec is
             * right and the project manager confirms the money exists; they
             * are different people and either can refuse for reasons the other
             * cannot judge. Collapsing them loses the audit trail that shows
             * which of the two happened.
             */
            $t->uuid('technical_approved_by')->nullable();
            $t->timestampTz('technical_approved_at')->nullable();

            $t->uuid('commercial_approved_by')->nullable();
            $t->timestampTz('commercial_approved_at')->nullable();

            $t->uuid('rejected_by')->nullable();
            $t->timestampTz('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('raised_by')->references('id')->on('users');
            $t->foreign('technical_approved_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('commercial_approved_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'number']);
            $t->index(['project_id', 'state']);
        });

        Schema::create('material_requisition_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('requisition_id');

            /*
             * WHICH SUBSYSTEM THIS MATERIAL IS FOR. Nullable, because bulk
             * consumables are not allocated to one subsystem. When it IS set,
             * this is the row that makes "material shortage" a computable fact
             * at the MC gate rather than a spreadsheet somebody maintains by
             * hand.
             */
            $t->uuid('subsystem_id')->nullable();

            $t->unsignedInteger('line_no');
            $t->string('item_code', 64);
            $t->text('description_en');
            $t->text('description_fa');
            $t->string('unit', 16);              // M, EA, KG, M2, M3, L, SET
            $t->decimal('quantity', 18, 3);

            // The spec the item must satisfy, e.g. "API 5L X60 PSL2".
            $t->string('specification', 255)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('requisition_id')->references('id')->on('material_requisitions')->cascadeOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();

            $t->unique(['requisition_id', 'line_no']);
            // The MC gate asks "what was requisitioned for this subsystem".
            $t->index(['subsystem_id']);
        });

        // -------------------------------------------------------------------
        // 2. Purchase order: "we have ordered it"
        // -------------------------------------------------------------------
        Schema::create('purchase_orders', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The BUYER. Tenant column, consistent with the rest of the schema.
            $t->uuid('company_id');
            // The VENDOR the order is placed with. Must be a VEN participant.
            $t->uuid('vendor_company_id');

            $t->string('number', 64);
            $t->unsignedInteger('sequence');
            $t->string('state', 24); // PurchaseOrderState

            $t->uuid('requisition_id')->nullable();

            $t->string('title_en');
            $t->string('title_fa');

            /*
             * Money. Nullable because an order can be raised before the price
             * is agreed, and because this prototype does not pretend to be a
             * finance system. Currency is a column rather than an assumption:
             * an Iranian project buys locally in ریال and imports in EUR, and
             * a system that hardcodes one of them produces nonsense totals on
             * the other.
             */
            $t->decimal('total_value', 18, 2)->nullable();
            $t->string('currency', 8)->nullable();

            // Expediting: what the vendor promised, and what we now believe.
            $t->date('promised_date')->nullable();
            $t->date('forecast_date')->nullable();

            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('vendor_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('requisition_id')->references('id')->on('material_requisitions')->nullOnDelete();
            $t->foreign('issued_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'number']);
            $t->index(['project_id', 'state']);
            $t->index(['vendor_company_id']);
        });

        Schema::create('purchase_order_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('purchase_order_id');
            // Which requisition line this satisfies. Nullable: a PO can carry
            // a line nobody requisitioned (a late addition), and the audit
            // should show that honestly rather than inventing a parent.
            $t->uuid('requisition_item_id')->nullable();
            $t->uuid('subsystem_id')->nullable();

            $t->unsignedInteger('line_no');
            $t->string('item_code', 64);
            $t->text('description_en');
            $t->text('description_fa');
            $t->string('unit', 16);
            $t->decimal('quantity', 18, 3);
            $t->decimal('unit_price', 18, 2)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $t->foreign('requisition_item_id')->references('id')->on('material_requisition_items')->nullOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();

            $t->unique(['purchase_order_id', 'line_no']);
        });

        // -------------------------------------------------------------------
        // 3. Inspection - ADVISORY (user's explicit choice)
        // -------------------------------------------------------------------
        /*
         * The result is recorded and shown everywhere the material is shown,
         * but it does NOT block a receipt. That was a deliberate instruction,
         * and it matches how sites actually run: material arrives at 2am, the
         * TPI signs on Sunday, and a system that refuses the receipt until
         * then just gets bypassed with a paper note nobody ever enters.
         *
         * The cost of advisory-only is that "received but failed inspection"
         * becomes a real state that somebody must look at. So the flag is
         * carried on the lot (see inspection_result below) rather than being
         * left to a report nobody runs.
         */
        Schema::create('material_inspections', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The INSPECTING body - TPI, PMC, MC or the owner.
            $t->uuid('company_id');
            $t->uuid('purchase_order_id')->nullable();

            $t->string('number', 64);
            $t->unsignedInteger('sequence');

            $t->string('result', 16);  // InspectionResult: PASS/FAIL/CONDITIONAL
            $t->string('stage', 24);   // WORKS | PRE_SHIPMENT | ON_ARRIVAL

            $t->text('findings_en')->nullable();
            $t->text('findings_fa')->nullable();

            $t->uuid('inspected_by');
            $t->timestampTz('inspected_at');

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $t->foreign('inspected_by')->references('id')->on('users');

            $t->unique(['project_id', 'number']);
        });

        // -------------------------------------------------------------------
        // 4. Lot: a named, traceable parcel of one item
        // -------------------------------------------------------------------
        Schema::create('material_lots', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The CUSTODIAN - whoever's warehouse holds it.
            $t->uuid('company_id');

            $t->uuid('purchase_order_id')->nullable();
            $t->uuid('purchase_order_item_id')->nullable();
            $t->uuid('subsystem_id')->nullable();

            $t->string('lot_code', 64);
            $t->string('item_code', 64);
            $t->text('description_en');
            $t->text('description_fa');
            $t->string('unit', 16);

            // Traceability, the reason this table exists.
            $t->string('heat_number', 64)->nullable();
            $t->string('serial_number', 64)->nullable();
            /*
             * The Material Test Certificate, as a row in the document
             * register rather than a file path. Nullable because the MTC often
             * arrives after the steel does, and a receipt that cannot be
             * recorded until the paperwork catches up is a receipt that gets
             * recorded in a notebook instead.
             */
            $t->uuid('mtc_document_id')->nullable();

            // Denormalised from the latest inspection so that every warehouse
            // screen can show it without a join. NULL = not inspected.
            $t->string('inspection_result', 16)->nullable();
            $t->uuid('inspection_id')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $t->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->nullOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('mtc_document_id')->references('id')->on('documents')->nullOnDelete();
            $t->foreign('inspection_id')->references('id')->on('material_inspections')->nullOnDelete();

            $t->unique(['project_id', 'lot_code']);
            $t->index(['project_id', 'item_code']);
            $t->index(['heat_number']);
        });

        // -------------------------------------------------------------------
        // 5. The movement ledger - append only
        // -------------------------------------------------------------------
        Schema::create('material_movements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The company performing the movement (the custodian).
            $t->uuid('company_id');
            $t->uuid('lot_id');

            $t->string('number', 64);
            $t->unsignedInteger('sequence');

            /*
             * RECEIPT (GRN) | ISSUE (MIV) | SITE (SDN) | RETURN (MRN)
             *
             * Sign convention lives in App\Support\MovementType::sign(), not
             * in the data: storing a signed quantity means a reader has to
             * know the convention to make sense of a row, and somebody will
             * eventually insert a negative receipt.
             */
            $t->string('type', 16);
            $t->decimal('quantity', 18, 3);

            // Where it went. Free text: a project's locations are its own
            // (LAYDOWN-2, WH-A RACK 14), and an enum here would be wrong
            // within a week.
            $t->string('from_location', 128)->nullable();
            $t->string('to_location', 128)->nullable();

            // For an issue or a site delivery: what it was issued FOR.
            $t->uuid('subsystem_id')->nullable();

            $t->text('note')->nullable();

            $t->uuid('performed_by');
            $t->timestampTz('performed_at');

            // A reversing entry points at the movement it cancels.
            $t->uuid('reverses_movement_id')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('lot_id')->references('id')->on('material_lots')->cascadeOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('performed_by')->references('id')->on('users');

            $t->unique(['project_id', 'number']);
            $t->index(['lot_id', 'type']);
            // "what has reached this subsystem" - the MC shortage query.
            $t->index(['subsystem_id', 'type']);
        });

        /*
         * The self-reference is added AFTER the table exists. Declared inside
         * Schema::create it fails with "no unique constraint matching given
         * keys" - PostgreSQL evaluates the foreign key against the table as it
         * stands mid-statement, before the primary key is in place.
         */
        Schema::table('material_movements', function (Blueprint $t): void {
            $t->foreign('reverses_movement_id')->references('id')->on('material_movements')->nullOnDelete();
        });

        // ---- RLS ----------------------------------------------------------
        $tables = [
            'material_requisitions', 'material_requisition_items',
            'purchase_orders', 'purchase_order_items',
            'material_inspections', 'material_lots', 'material_movements',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        /*
         * Requisitions: the buyer, the employer and the contract administrator.
         * Explicitly NOT vendors - an MR is the buyer's own planning and often
         * names quantities that would tell a competitor what the project is
         * short of.
         */
        DB::unprepared(<<<'SQL'
            CREATE POLICY material_requisitions_tenant_isolation ON material_requisitions
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR material_requisitions.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM project_company pc
                        JOIN companies c ON c.id = pc.company_id
                        WHERE pc.project_id = material_requisitions.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                          AND c.tenant_type IN ('OWN', 'PMC', 'MC')
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                OR EXISTS (
                    SELECT 1 FROM project_company pc
                    JOIN companies c ON c.id = pc.company_id
                    WHERE pc.project_id = material_requisitions.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                      AND c.tenant_type IN ('OWN', 'PMC', 'MC')
                )
            )
        SQL);

        // Requisition lines follow their parent exactly.
        DB::unprepared(<<<'SQL'
            CREATE POLICY material_requisition_items_tenant_isolation ON material_requisition_items
            USING (
                EXISTS (
                    SELECT 1 FROM material_requisitions mr
                    WHERE mr.id = material_requisition_items.requisition_id
                )
            )
            WITH CHECK (
                EXISTS (
                    SELECT 1 FROM material_requisitions mr
                    WHERE mr.id = material_requisition_items.requisition_id
                )
            )
        SQL);

        /*
         * Purchase orders: buyer, employer/PMC/MC, and THE NAMED VENDOR ONLY.
         * The vendor clause is the important one - it is what lets a vendor
         * log in and see its own order without seeing its competitors'.
         */
        DB::unprepared(<<<'SQL'
            CREATE POLICY purchase_orders_tenant_isolation ON purchase_orders
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR purchase_orders.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR vendor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM project_company pc
                        JOIN companies c ON c.id = pc.company_id
                        WHERE pc.project_id = purchase_orders.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                          AND c.tenant_type IN ('OWN', 'PMC', 'MC')
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY purchase_order_items_tenant_isolation ON purchase_order_items
            USING (
                EXISTS (
                    SELECT 1 FROM purchase_orders po
                    WHERE po.id = purchase_order_items.purchase_order_id
                )
            )
            WITH CHECK (
                EXISTS (
                    SELECT 1 FROM purchase_orders po
                    WHERE po.id = purchase_order_items.purchase_order_id
                )
            )
        SQL);

        // Inspections are quality records: every participant reads them,
        // inspecting bodies write them.
        DB::unprepared(<<<'SQL'
            CREATE POLICY material_inspections_tenant_isolation ON material_inspections
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR material_inspections.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = material_inspections.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = material_inspections.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        /*
         * Stock and movements are operational, not commercial. Everyone on the
         * project may read: the employer is entitled to know whether the valve
         * is on site, and hiding that produces the weekly spreadsheet this
         * module exists to kill. Only the custodian writes.
         */
        foreach (['material_lots', 'material_movements'] as $table) {
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_movements');
        Schema::dropIfExists('material_lots');
        Schema::dropIfExists('material_inspections');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('material_requisition_items');
        Schema::dropIfExists('material_requisitions');
    }
};
