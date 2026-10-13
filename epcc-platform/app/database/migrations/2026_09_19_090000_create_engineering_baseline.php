<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The engineering baseline, and reconciliation between its sources
 * (ADR-005 §2.1 and §2.2).
 *
 * THE PROBLEM. A line list, an MTO and a PCF parse are three statements about
 * the same physical reality, arriving at different times with different
 * authority. Give each its own table and "how much 6-inch pipe does SS-2201
 * need?" has three answers and no way to choose. So there is ONE neutral
 * table, and every row carries where it came from.
 *
 * THE USER'S RULING (2026-09-19): NOBODY WINS AUTOMATICALLY. When the MTO says
 * 120 m and the model says 134 m, the system records both and raises the
 * disagreement as a finding. It does not quietly pick the larger, the smaller
 * or the newer. On a real project that disagreement IS the finding - it
 * usually means the isometric was revised and the MTO was not re-issued, which
 * is exactly the error that puts 250 m of the wrong pipe on a truck.
 *
 * Batches are immutable snapshots (§2.2). A re-import of rev C does not
 * overwrite rev B; both survive, so "which revision did we buy against" has an
 * answer and the rev B → rev C diff is available as a report in its own right.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One import of one source file. Immutable once accepted.
         *
         * Separate from `model_imports` even though both ingest a file. That
         * table records what happened to the geometric MODEL - components,
         * ports, joints; this records a claim about QUANTITIES. Merging them
         * would mean one row could never answer either question cleanly.
         */
        Schema::create('baseline_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            $t->string('source_kind');       // XLSX_MTO | XLSX_LINELIST | PCF | MANUAL
            $t->string('filename')->nullable();
            $t->string('content_hash', 64)->nullable();

            // Which issue of which document this claim came from. Without it,
            // "the MTO says 120" is unattributable a year later.
            $t->uuid('source_document_id')->nullable();
            $t->string('revision_label')->nullable();

            // Scope. A batch usually covers one line or one subsystem; a
            // whole-project MTO leaves both null.
            $t->uuid('piping_line_id')->nullable();
            $t->uuid('subsystem_id')->nullable();

            $t->string('state')->default('DRAFT');  // DRAFT | ACCEPTED | SUPERSEDED
            $t->integer('row_count')->default(0);
            $t->integer('rejected_count')->default(0);
            $t->jsonb('warnings')->nullable();

            $t->uuid('imported_by');
            $t->timestamp('accepted_at')->nullable();
            $t->uuid('accepted_by')->nullable();
            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('source_document_id')->references('id')->on('documents')->nullOnDelete();
            $t->foreign('piping_line_id')->references('id')->on('piping_lines')->nullOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('imported_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['project_id', 'source_kind', 'state']);
            $t->index(['piping_line_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_batches ADD CONSTRAINT baseline_batches_state_known CHECK (
                state IN ('DRAFT', 'ACCEPTED', 'SUPERSEDED')
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_batches ADD CONSTRAINT baseline_batches_source_known CHECK (
                source_kind IN ('XLSX_MTO', 'XLSX_LINELIST', 'PCF', 'MANUAL')
            )
        SQL);

        // Acceptance is an act by a person, so it must name one.
        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_batches ADD CONSTRAINT baseline_batches_acceptance_has_provenance CHECK (
                state <> 'ACCEPTED' OR (accepted_at IS NOT NULL AND accepted_by IS NOT NULL)
            )
        SQL);

        /*
         * One claim: "this scope needs this much of this item".
         *
         * Never updated in place. A new revision is a new batch with new rows,
         * and the old rows stay - that is what makes "what changed between rev
         * B and rev C" a query rather than an archaeology exercise.
         */
        Schema::create('engineering_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('baseline_batch_id');

            $t->uuid('piping_line_id')->nullable();
            $t->uuid('subsystem_id')->nullable();

            // The identity of the thing. `item_code` is what procurement and
            // the warehouse already key on, so the baseline speaks their
            // language rather than inventing a parallel one.
            $t->string('item_code');
            $t->string('component_type')->nullable();   // our template code, when known
            $t->string('nominal_size')->nullable();
            $t->decimal('nominal_size_decimal', 10, 4)->nullable();
            $t->string('schedule')->nullable();
            $t->string('material_code')->nullable();
            $t->string('spec_code')->nullable();
            $t->text('description')->nullable();

            $t->decimal('quantity', 16, 4);
            $t->string('unit');                          // M | EA | KG ...

            $t->string('source_kind');

            /*
             * How much this source is trusted, 0-100. NOT used to pick a
             * winner - the user ruled that nothing wins automatically - but to
             * ORDER the evidence in front of the person who decides, and to
             * let a report say "the only claim here is a hand-typed one".
             */
            $t->unsignedSmallInteger('confidence')->default(50);

            $t->string('external_ref')->nullable();
            $t->integer('source_row')->nullable();
            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('baseline_batch_id')->references('id')->on('baseline_batches')->cascadeOnDelete();
            $t->foreign('piping_line_id')->references('id')->on('piping_lines')->nullOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();

            $t->index(['project_id', 'item_code']);
            $t->index(['baseline_batch_id', 'item_code']);
            $t->index(['piping_line_id', 'item_code']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE engineering_items ADD CONSTRAINT engineering_items_quantity_is_positive CHECK (
                quantity > 0
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE engineering_items ADD CONSTRAINT engineering_items_source_known CHECK (
                source_kind IN ('XLSX_MTO', 'XLSX_LINELIST', 'PCF', 'MANUAL')
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE engineering_items ADD CONSTRAINT engineering_items_confidence_is_a_percentage CHECK (
                confidence BETWEEN 0 AND 100
            )
        SQL);

        /*
         * A disagreement between two sources about one item in one scope.
         *
         * This table is the deliverable. Everything else here exists so that
         * this can be produced: the list of places where engineering and the
         * take-off do not agree, each with both numbers and neither of them
         * chosen for you.
         */
        Schema::create('baseline_discrepancies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            $t->uuid('piping_line_id')->nullable();
            $t->uuid('subsystem_id')->nullable();
            $t->string('item_code');
            $t->string('unit');

            $t->string('kind');   // QUANTITY | MISSING_IN_MODEL | MISSING_IN_MTO

            // Both claims, side by side and both preserved.
            $t->string('source_a');
            $t->decimal('quantity_a', 16, 4)->nullable();
            $t->uuid('batch_a_id')->nullable();

            $t->string('source_b');
            $t->decimal('quantity_b', 16, 4)->nullable();
            $t->uuid('batch_b_id')->nullable();

            $t->decimal('difference', 16, 4)->nullable();
            $t->decimal('difference_pct', 9, 4)->nullable();

            $t->string('state')->default('OPEN');   // OPEN | ACCEPTED_A | ACCEPTED_B | ACCEPTED_OTHER | DISMISSED
            $t->decimal('resolved_quantity', 16, 4)->nullable();
            $t->text('resolution_note')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();

            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_line_id')->references('id')->on('piping_lines')->nullOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('batch_a_id')->references('id')->on('baseline_batches')->nullOnDelete();
            $t->foreign('batch_b_id')->references('id')->on('baseline_batches')->nullOnDelete();
            $t->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['project_id', 'state']);
            $t->index(['piping_line_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_discrepancies ADD CONSTRAINT baseline_discrepancies_kind_known CHECK (
                kind IN ('QUANTITY', 'MISSING_IN_MODEL', 'MISSING_IN_MTO')
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_discrepancies ADD CONSTRAINT baseline_discrepancies_state_known CHECK (
                state IN ('OPEN', 'ACCEPTED_A', 'ACCEPTED_B', 'ACCEPTED_OTHER', 'DISMISSED')
            )
        SQL);

        /*
         * A resolution is a decision: it must say who, when, and what number
         * won. Without the CHECK a resolved row could carry no quantity at
         * all, and the shortage report would then subtract from nothing.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_discrepancies ADD CONSTRAINT baseline_discrepancies_resolution_has_provenance CHECK (
                state = 'OPEN'
                OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL)
            )
        SQL);

        // Picking a number means there must BE a number. DISMISSED is the
        // deliberate exception: "this is not a real difference, ignore it".
        DB::unprepared(<<<'SQL'
            ALTER TABLE baseline_discrepancies ADD CONSTRAINT baseline_discrepancies_acceptance_has_a_quantity CHECK (
                state IN ('OPEN', 'DISMISSED') OR resolved_quantity IS NOT NULL
            )
        SQL);

        /*
         * The reconciliation tolerance (the user's ruling: ONE flat percentage
         * for everything, not a table of per-item-kind rules).
         *
         * Stored per project rather than hard-coded: pipe is cut from stock
         * lengths and never matches to the metre, and how much slack is
         * acceptable is a commercial judgement that differs between an EPC
         * contract and a reimbursable one.
         */
        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->decimal('baseline_tolerance_pct', 6, 3)->default(2.000)->after('allow_auto_line_numbers');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE project_piping_config ADD CONSTRAINT project_piping_config_tolerance_is_sane CHECK (
                baseline_tolerance_pct >= 0 AND baseline_tolerance_pct <= 100
            )
        SQL);

        foreach (['baseline_batches', 'engineering_items', 'baseline_discrepancies'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant ON {$table}
                USING (project_id IN (SELECT project_id FROM project_memberships
                       WHERE company_id = current_setting('epcc.company_id', true)::uuid))
                WITH CHECK (project_id IN (SELECT project_id FROM project_memberships
                       WHERE company_id = current_setting('epcc.company_id', true)::uuid))
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE project_piping_config DROP CONSTRAINT IF EXISTS project_piping_config_tolerance_is_sane');

        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->dropColumn('baseline_tolerance_pct');
        });

        Schema::dropIfExists('baseline_discrepancies');
        Schema::dropIfExists('engineering_items');
        Schema::dropIfExists('baseline_batches');
    }
};
