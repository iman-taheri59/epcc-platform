<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006 phase 3 — joints.
 *
 * The entity Plant 3D does not have. There, a connection is geometry: two parts
 * whose ports touch. Here it is a construction object with a number, a fit-up
 * state, a shop/field split and — once QA/QC lands — a WPS, a welder and NDT
 * results. That difference is the whole reason this table exists rather than a
 * `connected_to_port_id` column on `ports`.
 *
 * Four decisions are baked into the shape.
 *
 * ORDERED PAIR, ENFORCED. `CHECK (port_a_id < port_b_id)` plus a UNIQUE on the
 * pair. Without the CHECK, (A,B) and (B,A) are two different rows that both
 * satisfy the UNIQUE, and the same weld gets counted twice in dia-inch. The
 * corrupt state is made unrepresentable rather than merely discouraged — the
 * same trick as the composite FK in ADR-004.
 *
 * DERIVED ATTRIBUTES ARE FROZEN, NOT COMPUTED ON READ. A joint inspected and
 * accepted at 6"/SCH40 must keep saying 6"/SCH40 even if someone later edits a
 * port. This is the opposite of the dia-inch rule below, and deliberately so:
 * size/schedule/thickness describe what was BUILT, dia-inch is a MEASURE of it.
 *
 * DIA-INCH IS ABSENT FROM THIS TABLE. ADR-010 section 1: computed as
 * min(portA, portB), never stored, because a stored copy can drift from the
 * ports and the error is invisible — it does not crash, it inflates progress.
 * Only an approved progress snapshot freezes it, and that is a different table.
 *
 * JOINT NUMBER IS NULLABLE, AND THAT IS NOT "NOT YET NUMBERED". A flanged joint
 * never gets a number (ADR-006 section 11.2); an unnumbered weld is a defect.
 * `numbering_policy` tells those two apart, and the partial unique index only
 * constrains the rows that actually carry a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joints', function ($table): void {
            $table->uuid('id')->primary();

            $table->uuid('project_id');
            $table->uuid('company_id');

            // Denormalised from the ports' components. A joint is always read
            // in the context of its line - the register, the open-ends
            // worklist, the dia-inch roll-up - and walking
            // joint -> port -> component -> line on every one of those reads
            // costs two joins to learn something that cannot change: a joint
            // never moves between lines.
            $table->uuid('piping_line_id');

            $table->uuid('port_a_id');
            $table->uuid('port_b_id');

            // Per-line ordinal, shared by welded and threaded joints
            // (ADR-006 section 11.1, the user's «شمارنده مشترک»). An INTEGER,
            // not a zero-padded string: padding is presentation, and storing
            // "007" turns "next number" into string surgery.
            $table->integer('joint_seq')->nullable();

            // REQUIRED = this joint must carry a number, and a null one is a
            // gap to be chased. NOT_APPLICABLE = it will never have one, and
            // the null is the correct final state. Without this column the two
            // are indistinguishable and every report has to guess.
            $table->string('numbering_policy', 24)->default('REQUIRED');

            // Derived from the two end types at creation and frozen.
            $table->string('joint_type', 24);

            // MODELLED -> FITTED. QA/QC states deferred at the user's
            // instruction; the column is a string rather than an enum type so
            // that adding them later is an application change, not a DDL one.
            $table->string('state', 24)->default('MODELLED');

            // Both mandatory on entering FITTED. The code is a STRING, not a
            // users FK: the fitter is very often a subcontractor's employee
            // who will never have a login, and a nullable FK would quietly
            // become "nobody" for exactly the joints that matter.
            $table->string('fitted_by_code', 64)->nullable();
            $table->timestampTz('fitted_at')->nullable();

            // Frozen at creation from the ports. See the class comment.
            $table->string('nominal_size', 16)->nullable();
            $table->decimal('nominal_size_decimal', 8, 4)->nullable();
            $table->string('schedule', 16)->nullable();
            $table->decimal('thickness_mm', 8, 3)->nullable();

            // PCF CATEGORY FABRICATION => SHOP, ERECTION => FIELD. Drives who
            // is responsible and which progress statement the joint lands in.
            $table->string('shop_field', 16)->nullable();

            $table->string('spool_id', 64)->nullable();
            $table->string('isometric_sheet_no', 64)->nullable();

            // VALID / WARNING / INVALID, with the reasons kept alongside.
            // A boolean would force every spec-break transition to be either a
            // lie or a blocker (ADR-006 section 5.4).
            $table->string('compatibility_state', 16)->default('VALID');
            $table->jsonb('compatibility_notes')->nullable();

            $table->string('source', 16)->default('MANUAL');
            $table->string('external_ref', 128)->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('piping_line_id')->references('id')->on('piping_lines')->cascadeOnDelete();

            // Deleting a component deletes its ports, which must delete the
            // joints hanging off them. Leaving an orphan joint pointing at a
            // vanished port is how a dia-inch total outlives the pipe it
            // measured.
            $table->foreign('port_a_id')->references('id')->on('ports')->cascadeOnDelete();
            $table->foreign('port_b_id')->references('id')->on('ports')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['port_a_id', 'port_b_id'], 'joints_port_pair_unique');
            $table->index(['project_id', 'piping_line_id'], 'joints_project_line_idx');
            $table->index(['piping_line_id', 'state'], 'joints_line_state_idx');
        });

        // A port can only be in one joint, on either side of the pair. The
        // pair-unique above does not give this: (A,B) and (A,C) are distinct
        // pairs, so without these two indexes port A could be welded to two
        // different things - which is exactly the state the is_connected flag
        // claims is impossible.
        DB::statement('CREATE UNIQUE INDEX joints_port_a_unique ON joints (port_a_id)');
        DB::statement('CREATE UNIQUE INDEX joints_port_b_unique ON joints (port_b_id)');

        // The ordered pair. Also rules out a port joined to itself, which no
        // application path can produce but a bad import certainly can.
        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_ordered_pair CHECK (port_a_id < port_b_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_seq_positive CHECK (
                joint_seq IS NULL OR joint_seq >= 1
            )
        SQL);

        // A joint that will never be numbered must not be carrying a number.
        // Without this, switching a weld to a flange leaves the old number
        // behind and it keeps appearing on reports.
        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_unnumbered_policy_has_no_seq CHECK (
                numbering_policy <> 'NOT_APPLICABLE' OR joint_seq IS NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_numbering_policy_known CHECK (
                numbering_policy IN ('REQUIRED', 'NOT_APPLICABLE')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_state_known CHECK (
                state IN ('MODELLED', 'FITTED')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_compatibility_state_known CHECK (
                compatibility_state IN ('VALID', 'WARNING', 'INVALID')
            )
        SQL);

        // Entering FITTED demands BOTH the fitter and the time. The same shape
        // as the AFC gate, which demands afc_confirmed_at AND afc_confirmed_by:
        // a state that records who but not when is not auditable, and the gap
        // is only ever noticed years later during a claim.
        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_fitted_needs_evidence CHECK (
                state <> 'FITTED'
                OR (fitted_by_code IS NOT NULL AND fitted_at IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_size_pair_complete CHECK (
                (nominal_size IS NULL AND nominal_size_decimal IS NULL)
                OR (nominal_size IS NOT NULL AND nominal_size_decimal IS NOT NULL)
            )
        SQL);

        // Per-line number uniqueness, and only over rows that have a number -
        // a plain UNIQUE would be satisfied by any number of NULLs in
        // PostgreSQL, which is the behaviour we want, but being explicit keeps
        // the index small on a project where most joints are flanged.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX joints_line_seq_unique
            ON joints (piping_line_id, joint_seq)
            WHERE joint_seq IS NOT NULL
        SQL);

        // The chase-list: numbered-but-not-yet-numbered joints.
        DB::statement(<<<'SQL'
            CREATE INDEX joints_awaiting_number_idx
            ON joints (project_id, piping_line_id)
            WHERE numbering_policy = 'REQUIRED' AND joint_seq IS NULL
        SQL);

        // ------------------------------------------------------------------
        // RLS. Copied from create_components_and_ports, which copied it from
        // create_piping_lines. There is no epcc_can_read_project() helper -
        // the read rule is spelled out from current_setting every time.
        // ------------------------------------------------------------------
        DB::unprepared('ALTER TABLE joints ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE joints FORCE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            CREATE POLICY joints_tenant_isolation ON joints
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR joints.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = joints.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = joints.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // ADR-013: an archived project is read-only. Both halves are needed -
        // WITH CHECK is never evaluated for DELETE, so the FOR DELETE policy
        // is not redundant (HANDOFF section 8).
        DB::unprepared(<<<'SQL'
            CREATE POLICY joints_archive_freeze ON joints
            AS RESTRICTIVE
            FOR ALL
            USING (true)
            WITH CHECK (epcc_project_is_writable(project_id))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY joints_archive_no_delete ON joints
            AS RESTRICTIVE
            FOR DELETE
            USING (epcc_project_is_writable(project_id))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('joints');
    }
};
