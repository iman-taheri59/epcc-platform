<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document register / CDE (SRS FR-ENG-01, FR-ENG-03, FR-ENG-04, FR-DOC-01).
 *
 * The first slice of the `doc` module. Starter plans already pay for it, so it
 * was the highest-priority gap: it rendered as "Soon" in the sidebar while
 * being a sold feature.
 *
 * SCOPE OF THIS SLICE
 * Deliverable register + ISO 19650 CDE states + revision history. Deliberately
 * NOT here: file storage/upload (FR-DOC-01 needs object storage), the in-browser
 * viewer, OCR search, transmittals, and the comment sheet. Those are separate
 * modules that hang off this register; shipping the register first means the
 * numbering and revision rules are settled before anything depends on them.
 *
 * WHY TWO TABLES
 * `documents` is the register entry - the thing that has a number and a title
 * and exists for the life of the project. `document_revisions` is the history:
 * every issued revision, its CDE state and suitability code, who issued it and
 * when. Collapsing them into one table loses the audit trail the whole module
 * exists to provide (FR-AUD-02: "what did the drawing register look like on
 * 2025-11-03?").
 */
return new class extends Migration
{
    /** New tables carrying a company_id tenant column. */
    private const TENANT_TABLES = ['documents', 'document_revisions'];

    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The originating company. RLS tenant column: a contractor sees its
            // own deliverables; the employer sees every participant's.
            $t->uuid('company_id');

            // FR-ENG-02: segment-based number. Stored whole AND in parts, so a
            // client-mandated scheme can be validated and re-sequenced without
            // re-parsing strings.
            $t->string('number', 128);
            $t->string('discipline', 32);          // CIVIL, PIPING, ELEC...
            $t->string('doc_type', 32);            // DWG, DS, MTO, CALC, SPEC...
            $t->unsignedInteger('sequence');

            // FR-I18N-05: bilingual master data is a requirement, not a nicety.
            $t->string('title_en', 512);
            $t->string('title_fa', 512);

            // Denormalised pointer to the current revision. A register that has
            // to aggregate its own history on every page load is the classic
            // way this screen becomes unusable at 20k documents.
            $t->uuid('current_revision_id')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            // A document number must be unique WITHIN a project, not globally:
            // two projects legitimately reuse the same client scheme.
            $t->unique(['project_id', 'number']);
            $t->index(['project_id', 'discipline']);
        });

        Schema::create('document_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->uuid('project_id');   // denormalised: RLS policies join on it
            $t->uuid('company_id');   // RLS tenant column

            // FR-ENG-04. Revisions are strings ('A', 'B', '0', '1') because
            // client schemes differ; `ordinal` is what actually sorts.
            $t->string('revision', 16);
            $t->unsignedInteger('ordinal');

            // FR-ENG-03: ISO 19650 CDE state.
            $t->string('cde_state', 16)->default('WIP');
            // Suitability code (S0-S7, A1-A5, B1-B5). Nullable: a WIP revision
            // legitimately has none until it is shared.
            $t->string('suitability', 8)->nullable();

            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->text('remarks')->nullable();

            $t->timestampsTz();

            $t->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('issued_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['document_id', 'revision']);
            $t->index(['project_id', 'cde_state']);
        });

        // The pointer is added after both tables exist, or the FK has nothing
        // to reference.
        Schema::table('documents', function (Blueprint $t): void {
            $t->foreign('current_revision_id')->references('id')
                ->on('document_revisions')->nullOnDelete();
        });

        // ---- RLS. Same policy shape as the core tables (ARC §5.1). ----
        // Copied deliberately rather than abstracted: an RLS policy is security
        // -critical SQL, and a clever shared helper that silently stops
        // applying to a new table is exactly the failure this guards against.
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            // FORCE applies the policy to the table owner too; without it the
            // owning role bypasses RLS and the backstop is decorative.
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            // Two things this policy MUST have, both learned the hard way:
            //
            //  * NULLIF(..., '') - a custom GUC reverts to the EMPTY STRING,
            //    not NULL, once a SET LOCAL commits, and ''::uuid raises
            //    `invalid input syntax for type uuid`. Standing rule 3.
            //
            //  * The `epcc.project_id` scope AND a project_company
            //    participation check. Filtering on company_id alone leaks
            //    ACROSS PROJECTS: scoped to PH-14, the owner still saw its own
            //    KISH-1 documents, because those rows are also "its own".
            //    Standing rule 7 - scope by participation, not by company.
            //
            // This mirrors the current policy on payment_certificates
            // (migration 110000), which is the shape every tenant table must
            // use. Copying the ORIGINAL 100100 policy is what introduced both
            // defects here.
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    -- Optional project scope. Unset => all permitted projects.
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND
                    (
                        -- Own rows, but only on a project we participate in.
                        (
                            company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            AND EXISTS (
                                SELECT 1 FROM project_company pc
                                WHERE pc.project_id = {$table}.project_id
                                  AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                        OR
                        -- Employer sees every participant's rows on its projects.
                        EXISTS (
                            SELECT 1 FROM projects p
                            WHERE p.id = {$table}.project_id
                              AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
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
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        }

        Schema::table('documents', function (Blueprint $t): void {
            $t->dropForeign(['current_revision_id']);
        });

        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('documents');
    }
};
