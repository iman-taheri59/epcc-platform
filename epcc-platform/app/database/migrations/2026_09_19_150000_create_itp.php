<?php

declare(strict_types=1);

use App\Support\Itp\InterventionCode;
use App\Support\Itp\StageResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection and Test Plans (ITP).
 *
 * SHAPE OF THE PROBLEM
 * --------------------
 * An ITP is a table of activities. Each row carries acceptance criteria, a
 * method, a frequency and the records it produces. Against each row, EVERY
 * participating party holds an intervention level: H, W, M or R. That party
 * grid is the point of the document - it is what people argue about - so it
 * is a table (`itp_stage_parties`), not a jsonb blob. A blob cannot answer
 * "which stages does the TPI hold in this project", which is the first
 * question a TPI coordinator asks every morning.
 *
 * FIVE TABLES
 *   itp_templates        a reusable library ITP (per discipline, no project)
 *   itps                 a project's copy, discipline-scoped
 *   itp_stages           the numbered rows of one ITP
 *   itp_stage_parties    intervention code per party per stage
 *   itp_records          one stage applied to one real thing, with a verdict
 *
 * THE USER'S RULINGS APPLIED HERE
 *   - Piping and Pipeline are SEPARATE disciplines, each with its own ITP.
 *     Enforced by `itps_project_discipline_unique`: one active ITP per
 *     discipline per project.
 *   - A HOLD blocks RELEASE (issue from store to site), never the goods
 *     receipt. The truck arrives at 2am; refusing the receipt only produces a
 *     paper note nobody types in. So nothing here touches `material_movements`
 *     of type RECEIPT; the gate is read by WarehouseService::issue().
 *   - Templates are COPIED into a project, never referenced live. Editing the
 *     library must not silently alter the signed ITP of a running project.
 *   - No approval workflow yet (the user deferred it). `state` exists with
 *     DRAFT|ACTIVE|SUPERSEDED so adding one later is a data change, not a
 *     schema migration - but there is deliberately no APPROVED state today,
 *     because an unused approval state invites code that pretends to check it.
 */
return new class extends Migration
{
    /** Disciplines that can own an ITP. PIPING and PIPELINE are distinct. */
    private const DISCIPLINES = [
        'CIVIL', 'STRUCT', 'ARCH', 'MECH', 'PIPING', 'PIPELINE', 'PROCESS',
        'ELEC', 'INST', 'HVAC', 'HSE', 'TELECOM', 'COATING', 'WELDING',
    ];

    public function up(): void
    {
        $codes = "'".implode("','", InterventionCode::ALL)."'";
        $results = "'".implode("','", StageResult::ALL)."'";
        $disciplines = "'".implode("','", self::DISCIPLINES)."'";

        // ---------------------------------------------------------------- 1
        // The library. Not project data: no project_id, no RLS. A pack of
        // standard ITPs the platform ships and an admin can extend.
        Schema::create('itp_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('discipline', 32);
            $table->string('title_en');
            $table->string('title_fa');
            $table->text('scope_en')->nullable();
            $table->text('scope_fa')->nullable();
            $table->string('revision_label', 32)->nullable();
            /** Stages, as a document. Copied out on use; never read at runtime. */
            $table->jsonb('stages')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('discipline');
        });

        DB::statement("ALTER TABLE itp_templates ADD CONSTRAINT itp_templates_discipline_known CHECK (discipline IN ({$disciplines}))");

        // ---------------------------------------------------------------- 2
        Schema::create('itps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained();
            $table->string('discipline', 32);
            $table->string('number', 64);
            $table->string('title_en');
            $table->string('title_fa');
            $table->text('scope_en')->nullable();
            $table->text('scope_fa')->nullable();
            $table->string('revision_label', 32)->nullable();
            $table->string('state', 24)->default('DRAFT');
            /** Which library ITP this was copied from, for provenance only. */
            $table->foreignUuid('source_template_id')->nullable()->constrained('itp_templates')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'discipline', 'state']);
        });

        DB::statement("ALTER TABLE itps ADD CONSTRAINT itps_discipline_known CHECK (discipline IN ({$disciplines}))");
        DB::statement("ALTER TABLE itps ADD CONSTRAINT itps_state_known CHECK (state IN ('DRAFT','ACTIVE','SUPERSEDED'))");

        /*
         * One ACTIVE ITP per discipline per project. A partial unique index
         * rather than a plain unique, so superseded revisions pile up
         * harmlessly while exactly one is in force. Without this, two ACTIVE
         * piping ITPs both look authoritative and the gate reads whichever the
         * query happened to order first.
         */
        DB::statement(
            "CREATE UNIQUE INDEX itps_one_active_per_discipline
             ON itps (project_id, discipline) WHERE state = 'ACTIVE'"
        );

        // ---------------------------------------------------------------- 3
        Schema::create('itp_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained();
            $table->foreignUuid('itp_id')->constrained('itps')->cascadeOnDelete();
            /** The printed row number: "3.2". Free text because clients number oddly. */
            $table->string('clause', 32);
            /** Sort key. Separate from `clause` because "3.10" must sort after "3.9". */
            $table->integer('sequence');
            $table->string('title_en');
            $table->string('title_fa');
            $table->text('acceptance_en')->nullable();
            $table->text('acceptance_fa')->nullable();
            /** VI, DI, UT, MT, PT, RT, PMI, LT, FT, DR - free text, clients vary. */
            $table->string('method', 64)->nullable();
            /** "Each lot", "1 in 10", "100%". Free text on purpose. */
            $table->string('frequency_en', 128)->nullable();
            $table->string('frequency_fa', 128)->nullable();
            /** Reference spec/code: "ASME B31.3 §341". */
            $table->string('reference', 128)->nullable();
            /** The document this stage produces: "MTC", "PMI report". */
            $table->string('record_en', 128)->nullable();
            $table->string('record_fa', 128)->nullable();
            /** Hours of notice owed for H and W. */
            $table->integer('notice_hours')->nullable();
            $table->timestamps();

            $table->unique(['itp_id', 'clause']);
            $table->index(['itp_id', 'sequence']);
        });

        DB::statement('ALTER TABLE itp_stages ADD CONSTRAINT itp_stages_notice_sane CHECK (notice_hours IS NULL OR notice_hours BETWEEN 0 AND 2160)');

        // ---------------------------------------------------------------- 4
        // The party grid. One row per (stage, party) that has ANY involvement;
        // "no involvement" is the absence of a row, not a '-' value, so the
        // grid stays sparse and a query for "the TPI's hold points" is a plain
        // indexed lookup.
        Schema::create('itp_stage_parties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained();
            $table->foreignUuid('itp_stage_id')->constrained('itp_stages')->cascadeOnDelete();
            /*
             * The party is identified by TENANT TYPE (OWN, EPC, PMC, MC, TPI,
             * VEN...), not by a company id.
             *
             * An ITP is written before the TPI firm is appointed, and it
             * survives that firm being replaced mid-project. Binding the grid
             * to a company row would make a signed quality document depend on
             * a procurement decision that has not happened yet. The concrete
             * company is resolved when a record is signed, and stored THERE.
             */
            $table->string('tenant_type', 8);
            $table->string('intervention', 2);
            $table->timestamps();

            $table->unique(['itp_stage_id', 'tenant_type']);
            $table->index(['project_id', 'tenant_type', 'intervention']);
        });

        DB::statement("ALTER TABLE itp_stage_parties ADD CONSTRAINT itp_stage_parties_code_known CHECK (intervention IN ({$codes}))");

        // ---------------------------------------------------------------- 5
        // One stage applied to one real thing. Today the only thing is a
        // material lot; `subject_kind` exists so welds and spools attach later
        // without a migration that rewrites this table.
        Schema::create('itp_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained();
            $table->foreignUuid('itp_stage_id')->constrained('itp_stages')->cascadeOnDelete();

            $table->string('subject_kind', 24);
            $table->foreignUuid('material_lot_id')->nullable()->constrained('material_lots')->cascadeOnDelete();

            $table->string('result', 24)->default(StageResult::PENDING);
            $table->text('findings_en')->nullable();
            $table->text('findings_fa')->nullable();

            /** Who actually signed, and for which party. */
            $table->foreignUuid('signed_by')->nullable()->constrained('users');
            $table->foreignUuid('signed_company_id')->nullable()->constrained('companies');
            $table->string('signed_tenant_type', 8)->nullable();
            $table->timestamp('signed_at')->nullable();

            /** Notice served for H and W. */
            $table->timestamp('notified_at')->nullable();
            $table->foreignUuid('notified_by')->nullable()->constrained('users');
            /** How the invitation was evidenced: letter number, email, transmittal. */
            $table->string('notice_reference', 128)->nullable();

            /** Evidence: the MTC, the NDT report. */
            $table->foreignUuid('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->timestamps();

            $table->unique(['itp_stage_id', 'material_lot_id']);
            $table->index(['project_id', 'result']);
            $table->index('material_lot_id');
        });

        DB::statement("ALTER TABLE itp_records ADD CONSTRAINT itp_records_result_known CHECK (result IN ({$results}))");
        DB::statement("ALTER TABLE itp_records ADD CONSTRAINT itp_records_subject_known CHECK (subject_kind IN ('MATERIAL_LOT'))");

        /*
         * A material-lot record must actually name a lot. Written as an
         * equivalence so that adding 'WELD' later cannot accidentally inherit
         * the lot requirement.
         */
        DB::statement(
            "ALTER TABLE itp_records ADD CONSTRAINT itp_records_subject_present CHECK (
                (subject_kind = 'MATERIAL_LOT') = (material_lot_id IS NOT NULL)
            )"
        );

        /*
         * A settled verdict must carry its signature. Same provenance
         * discipline as the rest of the schema: a PASSED row with no signer
         * and no time is not evidence of anything, and it is exactly what a
         * half-written importer produces.
         */
        DB::statement(
            "ALTER TABLE itp_records ADD CONSTRAINT itp_records_signed_when_settled CHECK (
                result IN ('PENDING','NOTIFIED')
                OR (signed_by IS NOT NULL AND signed_at IS NOT NULL AND signed_tenant_type IS NOT NULL)
            )"
        );

        /*
         * WAIVED means "the invited party did not come and we proceeded". That
         * claim is worthless without the invitation, so the notice is
         * mandatory for it. This is the constraint that stops WAIVED becoming
         * a convenient synonym for "skip this stage".
         */
        DB::statement(
            "ALTER TABLE itp_records ADD CONSTRAINT itp_records_waiver_needs_notice CHECK (
                result <> 'WAIVED' OR (notified_at IS NOT NULL AND notice_reference IS NOT NULL)
            )"
        );

        DB::statement(
            "ALTER TABLE itp_records ADD CONSTRAINT itp_records_notified_has_time CHECK (
                (notified_at IS NULL) = (notified_by IS NULL)
            )"
        );

        // ------------------------------------------------------------- RLS
        // Same policy shape as every other tenant table: visibility follows
        // the company's PROJECT MEMBERSHIP, not a project GUC. Copied
        // deliberately rather than invented - a table whose policy differs
        // from its neighbours is a leak waiting for someone to find it.
        foreach (['itps', 'itp_stages', 'itp_stage_parties', 'itp_records'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
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
        Schema::dropIfExists('itp_records');
        Schema::dropIfExists('itp_stage_parties');
        Schema::dropIfExists('itp_stages');
        Schema::dropIfExists('itps');
        Schema::dropIfExists('itp_templates');
    }
};
