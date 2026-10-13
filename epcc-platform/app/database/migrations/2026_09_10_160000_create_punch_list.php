<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA/QC: subsystems, punch list and Mechanical Completion certificates.
 * SRS FR-COM-04 (punch list) and FR-COM-05 (MC/RFSU certificates),
 * acceptance journey 5.
 *
 * Three tables:
 *   subsystems         - the thing that gets mechanically completed
 *   punch_items        - defects found against a subsystem
 *   mc_certificates    - the certificate, which cannot be issued while an
 *                        A-item is open unless the owner records an exception
 *
 * RLS note: a punch list is NOT private commercial data. Every participant on
 * the project can see every punch item on it - the MC body raises items against
 * the contractor's work, the consultant verifies them, and the owner signs the
 * certificate. Hiding rows from any of those parties breaks the workflow. This
 * is the same reasoning as the review-comment table, and the opposite of the
 * certificate table, which is genuinely per-company commercial data.
 *
 * Writes are narrower than reads, but not to "own rows": the raiser and the
 * responsible company are different companies by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A subsystem is the unit of mechanical completion: a pump set, a
        // metering skid, a section of line. Punch items hang off it, and the
        // MC certificate is issued against it.
        Schema::create('subsystems', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The company responsible for delivering it - normally the EPC.
            // This is the RLS tenant column, but reads are open (see policy).
            $t->uuid('company_id');

            $t->string('code', 64);
            $t->string('name_en');
            $t->string('name_fa');
            $t->string('discipline', 32);

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $t->unique(['project_id', 'code']);
        });

        Schema::create('punch_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('subsystem_id');

            // WHO RAISED IT. Named the tenant column for consistency with the
            // rest of the schema, but it does NOT mean "owns the row" here.
            $t->uuid('company_id');
            // WHO MUST FIX IT. Different company - that is the entire point of
            // a punch list. Nullable until the item is assigned.
            $t->uuid('responsible_company_id')->nullable();

            $t->string('number', 64);
            $t->unsignedInteger('sequence');

            $t->string('category', 4);   // PunchCategory
            $t->string('state', 16);     // PunchState

            $t->text('description_en');
            $t->text('description_fa');
            // Where on the plant. Free text now; the SRS wants a P&ID pin and a
            // 3D location, which needs a viewer we do not have.
            $t->string('location', 255)->nullable();

            $t->uuid('raised_by');
            $t->timestampTz('raised_at');

            $t->uuid('fixed_by')->nullable();
            $t->timestampTz('fixed_at')->nullable();
            $t->text('fix_note')->nullable();

            $t->uuid('verified_by')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->text('verification_note')->nullable();

            $t->uuid('closed_by')->nullable();
            $t->timestampTz('closed_at')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('responsible_company_id')->references('id')->on('companies')->nullOnDelete();
            $t->foreign('raised_by')->references('id')->on('users');
            $t->foreign('fixed_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'number']);
            // The gate query is "open A-items on this subsystem" - index it.
            $t->index(['subsystem_id', 'category', 'state']);
        });

        Schema::create('mc_certificates', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('subsystem_id');
            // The company that ISSUED the certificate - the MC body.
            $t->uuid('company_id');

            $t->string('number', 64);
            $t->unsignedInteger('sequence');
            $t->string('state', 16); // DRAFT | ISSUED

            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at')->nullable();

            /*
             * The owner-approved exception (SRS journey 5: "unless Owner-approved
             * exception is recorded"). Three columns, not a boolean, because the
             * acceptance criterion is that the exception is RECORDED: who allowed
             * it, when, and on what grounds. A bare flag would satisfy the code
             * and fail the audit.
             */
            $t->uuid('exception_approved_by')->nullable();
            $t->timestampTz('exception_approved_at')->nullable();
            $t->text('exception_reason')->nullable();
            // Snapshot of which A-items were open when the exception was granted.
            // Derived data would silently change as items are closed later, and
            // the certificate must keep saying what was true on the day.
            $t->jsonb('exception_items')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('exception_approved_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'number']);
            // One live certificate per subsystem.
            $t->unique(['subsystem_id']);
        });

        // ---- RLS ----------------------------------------------------------
        foreach (['subsystems', 'punch_items', 'mc_certificates'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            // FORCE, so the owning role is not exempt (standing rule 2).
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        // Subsystems: readable by every participant, writable by the company
        // responsible for delivery.
        DB::unprepared(<<<'SQL'
            CREATE POLICY subsystems_tenant_isolation ON subsystems
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR subsystems.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = subsystems.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = subsystems.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        /*
         * Punch items: read open to all participants (a walkdown is a shared
         * activity), write allowed to the RAISER or the RESPONSIBLE company.
         *
         * Restricting writes to company_id alone would stop the contractor
         * marking its own item fixed, which is the main write on the table.
         * This is the same two-party shape as document_review_comments, and it
         * was reached the same way: by asking who legitimately needs to write
         * before copying a policy.
         */
        DB::unprepared(<<<'SQL'
            CREATE POLICY punch_items_tenant_isolation ON punch_items
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR punch_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = punch_items.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR responsible_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = punch_items.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = punch_items.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // MC certificates: everyone on the project reads them (the whole point
        // is that the owner and the contractor agree the plant is complete);
        // the issuing body and the employer write.
        DB::unprepared(<<<'SQL'
            CREATE POLICY mc_certificates_tenant_isolation ON mc_certificates
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR mc_certificates.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = mc_certificates.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = mc_certificates.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = mc_certificates.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }

    public function down(): void
    {
        foreach (['mc_certificates', 'punch_items', 'subsystems'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            Schema::dropIfExists($table);
        }
    }
};
