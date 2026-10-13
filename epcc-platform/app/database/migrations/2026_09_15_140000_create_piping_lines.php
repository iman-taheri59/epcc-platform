<?php

declare(strict_types=1);

use App\Support\LineReleaseState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006 phase 1: the piping line register, its numbering configuration and
 * the AFC gate.
 *
 * Three tables:
 *
 *   project_piping_config  one row per project - the line-number pattern and
 *                          the project registers (services, coatings). The
 *                          PMS is a project document, so the format is data,
 *                          never code (ADR-006 §4).
 *   piping_lines           the register itself.
 *   line_documents         AFC evidence: which CDE documents prove this line
 *                          is approved for construction.
 *
 * Piping classes get their own table because ADR-006 §11.5 draws a hard line
 * between universal reference data (sizes, schedules - files, ADR-007) and
 * per-project registers (classes, services, coatings - tenant tables). A class
 * belongs to the project's PMS and must be selectable, not typed.
 */
return new class extends Migration
{
    /**
     * Tables that carry project data and therefore need the tenant policy,
     * the archive freeze (ADR-013) and a project_id index.
     *
     * @var list<string>
     */
    private const TENANT_TABLES = [
        'project_piping_config',
        'piping_classes',
        'piping_lines',
        'line_documents',
    ];

    public function up(): void
    {
        // ------------------------------------------------------------------
        // Per-project numbering + registers
        // ------------------------------------------------------------------
        Schema::create('project_piping_config', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            // The typed-token pattern (ADR-006 §4). jsonb, not a format string:
            // the tokens drive composing, parsing, validation and the preview.
            $t->jsonb('line_number_pattern');

            // Registers the user asked to pre-register (§11.5). Services and
            // coatings are short, flat and project-specific - a jsonb list is
            // honest about that and avoids two tables that would only ever be
            // read as a picklist. Piping classes get a real table because they
            // carry attributes and will later own the PMS rules of ADR-008.
            $t->jsonb('services')->default('[]');
            $t->jsonb('coatings')->default('[]');
            $t->jsonb('insulation_types')->default('[]');

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();

            // One configuration per project. A second row would mean two
            // numbering schemes silently competing.
            $t->unique('project_id');
        });

        Schema::create('piping_classes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            $t->string('code', 32);              // A1, B2, CS150...
            $t->string('description_en')->nullable();
            $t->string('description_fa')->nullable();
            $t->string('material', 64)->nullable();       // CS, SS316, duplex
            $t->string('pressure_rating', 16)->nullable(); // 150#, 300#
            $t->string('corrosion_allowance', 16)->nullable();

            // ADR-008 will hang size bands and branch tables off this row.
            // Nothing here anticipates that shape: guessing it now would be a
            // migration to undo later.
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();

            $t->unique(['project_id', 'code']);
        });

        // ------------------------------------------------------------------
        // The register
        // ------------------------------------------------------------------
        Schema::create('piping_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('subsystem_id');

            $t->string('line_number', 128);

            // The parsed tokens, kept so the line can be re-validated if the
            // pattern changes - and so an imported number that does not fit the
            // pattern can still be stored with a record of what we could read.
            $t->jsonb('number_tokens')->nullable();

            // Sequence is the allocated part of the number. Nullable because a
            // line imported with an existing number was never allocated one by
            // us (ADR-006 §10.2: import must not invent numbers).
            $t->integer('sequence')->nullable();

            // --- the attribute tokens, as real columns ---
            // nominal_size is a LABEL from the reference pack ("2 1/2"), not a
            // number: ADR-007 stores four representations and the label is the
            // one an engineer writes. npsDecimal is derivable from it.
            $t->string('nominal_size', 16)->nullable();
            $t->string('service_code', 16)->nullable();
            $t->uuid('piping_class_id')->nullable();
            $t->string('coating_code', 16)->nullable();

            // --- design conditions, each with its unit ---
            // Units are stored next to every value. A column called
            // design_pressure with an implied unit is a defect waiting for its
            // first foreign vendor (ADR-006 §5.1).
            $t->decimal('design_pressure', 12, 4)->nullable();
            $t->string('design_pressure_unit', 12)->nullable();
            $t->decimal('design_temperature', 12, 4)->nullable();
            $t->string('design_temperature_unit', 12)->nullable();
            $t->decimal('operating_pressure', 12, 4)->nullable();
            $t->string('operating_pressure_unit', 12)->nullable();
            $t->decimal('operating_temperature', 12, 4)->nullable();
            $t->string('operating_temperature_unit', 12)->nullable();

            $t->decimal('test_pressure', 12, 4)->nullable();
            $t->string('test_pressure_unit', 12)->nullable();
            $t->string('test_fluid', 32)->nullable();
            $t->string('test_type', 32)->nullable();

            $t->string('insulation_type', 32)->nullable();
            $t->decimal('insulation_thickness_mm', 8, 2)->nullable();
            $t->string('tracing_type', 32)->nullable();

            // Free text until equipment exists (phase 3b).
            $t->string('from_ref')->nullable();
            $t->string('to_ref')->nullable();

            // --- the AFC gate ---
            $t->string('release_state', 24)->default(LineReleaseState::DRAFT);
            $t->timestampTz('afc_confirmed_at')->nullable();
            $t->uuid('afc_confirmed_by')->nullable();
            $t->timestampTz('released_at')->nullable();
            $t->uuid('released_by')->nullable();
            $t->string('release_note', 500)->nullable();

            // Plant 3D Line Group PnPID / PCF PIPELINE-REFERENCE.
            $t->string('external_ref', 128)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->cascadeOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->nullOnDelete();
            $t->foreign('afc_confirmed_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('released_by')->references('id')->on('users')->nullOnDelete();

            // The line number is the identity an engineer uses. Unique per
            // project, not per subsystem: two subsystems sharing a line number
            // would make every drawing reference ambiguous.
            $t->unique(['project_id', 'line_number']);

            $t->index(['project_id', 'subsystem_id']);
            $t->index(['project_id', 'release_state']);
        });

        // ------------------------------------------------------------------
        // AFC evidence
        // ------------------------------------------------------------------
        Schema::create('line_documents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_line_id');
            $t->uuid('document_id');

            // What this document proves for this line: ISOMETRIC, PID, PMS,
            // LINE_LIST, STRESS. A free string would make "is there an
            // isometric?" unanswerable, so it is constrained below.
            $t->string('evidence_kind', 24);

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_line_id')->references('id')->on('piping_lines')->cascadeOnDelete();
            // RESTRICT, not cascade: deleting a document that is somebody's AFC
            // evidence must be refused, not silently allowed to erase the
            // reason a line was approved.
            $t->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();

            $t->unique(['piping_line_id', 'document_id', 'evidence_kind']);
            $t->index(['project_id', 'piping_line_id']);
        });

        // ------------------------------------------------------------------
        // CHECK constraints
        // ------------------------------------------------------------------
        $states = "'".implode("','", LineReleaseState::all())."'";
        DB::statement("ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_state_is_known CHECK (release_state IN ({$states}))");

        // Provenance, exactly as the project archive does it: a state that
        // records who and when is only trustworthy if the database insists.
        DB::statement(<<<SQL
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_afc_has_provenance CHECK (
                release_state = 'DRAFT'
                OR (afc_confirmed_at IS NOT NULL AND afc_confirmed_by IS NOT NULL)
            )
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_release_has_provenance CHECK (
                release_state <> 'RELEASED'
                OR (released_at IS NOT NULL AND released_by IS NOT NULL)
            )
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE line_documents ADD CONSTRAINT line_documents_kind_is_known CHECK (
                evidence_kind IN ('ISOMETRIC','PID','PMS','LINE_LIST','STRESS','SUPPORT_LIST','OTHER')
            )
        SQL);

        // A sequence, when present, is positive. Zero or negative would mean
        // the allocator went wrong and is better caught here than printed on a
        // drawing.
        DB::statement('ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_sequence_positive CHECK (sequence IS NULL OR sequence > 0)');

        // ------------------------------------------------------------------
        // RLS - identical clause to subsystems_tenant_isolation
        // ------------------------------------------------------------------
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

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

            // ADR-013: an archived project is read-only. Both halves are
            // needed - WITH CHECK catches INSERT/UPDATE, and a separate
            // FOR DELETE policy is required because WITH CHECK is never
            // evaluated for DELETE (HANDOFF §8).
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE
                FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_no_delete ON {$table}
                AS RESTRICTIVE
                FOR DELETE
                USING (epcc_project_is_writable(project_id))
            SQL);
        }

        // ADR-012: every index leads with project_id so the tenant predicate
        // can be satisfied by an index scan rather than a filter.
        DB::statement('CREATE INDEX piping_lines_afc_pending_idx ON piping_lines (project_id, subsystem_id) WHERE release_state = \'DRAFT\'');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS {$table}_archive_no_delete ON {$table}");
            DB::unprepared("DROP POLICY IF EXISTS {$table}_archive_freeze ON {$table}");
            DB::unprepared("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        }

        Schema::dropIfExists('line_documents');
        Schema::dropIfExists('piping_lines');
        Schema::dropIfExists('piping_classes');
        Schema::dropIfExists('project_piping_config');
    }
};
