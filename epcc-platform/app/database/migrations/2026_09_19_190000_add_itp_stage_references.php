<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An ITP row must state, per the user:
 *
 *   شماره ردیف · فعالیت‌ها · استانداردها · رفرنس‌ها و اسپک‌های مرتبط پروژه ·
 *   شماره فرم‌های گزارش بازرسی · نقش هر یک از ارکان
 *
 * Row number, activity and the party grid already existed. This migration adds
 * the three that did not, and it deliberately splits what the original single
 * `reference` column had run together.
 *
 * WHY A STANDARD AND A PROJECT SPEC ARE NOT THE SAME COLUMN
 * ---------------------------------------------------------
 * `ASME B31.3` is an external code. We do not own it, it is not in our document
 * register, it changes on the committee's schedule, and every project on the
 * platform cites the same string. Storing it as text is correct.
 *
 * "Technical specification for line pipe purchase, Rev. B" is a PROJECT
 * document. It already lives in `documents`, it is revision-controlled there,
 * and it is the thing an inspector actually argues about on site. Storing that
 * as text would mean the day the spec is reissued at Rev. C, every ITP silently
 * keeps pointing at the old one — with no way to even detect it. So a project
 * spec is a FOREIGN KEY, and the revision follows the register.
 *
 * Same reasoning for the inspection report form. "QCF-PIP-014" is not a label;
 * it is a controlled form with its own revision, and handing an inspector the
 * superseded revision of a form is a real finding in an audit.
 *
 * Both are many-to-many: one stage cites several specs, one spec is cited by
 * many stages. Hence two link tables rather than columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ 1
        // Split the old `reference` column.
        //
        // `reference` stays, renamed in MEANING to "external codes and
        // standards only" — free text, because we do not control that
        // vocabulary. The project-controlled halves move to link tables below.
        Schema::table('itp_stages', function (Blueprint $table): void {
            // The inspection report form NUMBER as printed on the paper, for
            // the common case where the form is not (yet) a registered
            // document. The FK table below is the strong version; this is the
            // honest fallback, because on a real project half the forms are
            // client-issued PDFs that never reach our register.
            $table->string('form_number', 64)->nullable()->after('record_fa');
        });

        DB::statement("COMMENT ON COLUMN itp_stages.reference IS
            'External codes and standards only (ASME B31.3, EN 10204). Project documents go to itp_stage_documents.'");

        DB::statement("COMMENT ON COLUMN itp_stages.form_number IS
            'Inspection report form number as printed. Registered forms are additionally linked via itp_stage_documents with usage=FORM.'");

        // ------------------------------------------------------------------ 2
        // Project references: specs, drawings, procedures, and the report form
        // when it is a registered document.
        //
        // ONE table with a `usage` discriminator rather than three tables. The
        // relationship is identical in every case — stage cites document — and
        // the only thing that differs is why. Three tables would triple the RLS
        // surface to express one idea.
        Schema::create('itp_stage_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained();
            $table->foreignUuid('itp_stage_id')->constrained('itp_stages')->cascadeOnDelete();

            /*
             * restrictOnDelete, NOT cascade.
             *
             * If somebody deletes a specification that an active ITP cites,
             * the right answer is to refuse, not to quietly drop the citation
             * and leave a signed inspection plan referring to nothing. A
             * superseded spec is handled by the register's revision chain, not
             * by deletion.
             */
            $table->foreignUuid('document_id')->constrained('documents')->restrictOnDelete();

            /*
             * SPEC      project technical specification / datasheet
             * DRAWING   isometric, GA, P&ID cited by the activity
             * PROCEDURE ITP-adjacent method statement, WPS, NDE procedure
             * FORM      the inspection report form to be filled in
             */
            $table->string('usage', 16)->default('SPEC');

            // Optional pin: "Rev. B specifically", for the rare stage that was
            // agreed against a named revision and must not follow the register
            // forward. Null = always the current revision, which is the sane
            // default and what a QA manager expects.
            $table->foreignUuid('pinned_revision_id')->nullable()
                ->constrained('document_revisions')->nullOnDelete();

            // A clause or sheet inside the cited document: "§7.3", "Sheet 2".
            $table->string('locator', 64)->nullable();

            $table->timestamps();

            // The same document may be cited by one stage twice only if the
            // usage differs (a procedure that is also the form, say).
            $table->unique(['itp_stage_id', 'document_id', 'usage']);
            $table->index(['project_id', 'usage']);
            $table->index('document_id');
        });

        DB::statement("ALTER TABLE itp_stage_documents ADD CONSTRAINT itp_stage_documents_usage_known
            CHECK (usage IN ('SPEC','DRAWING','PROCEDURE','FORM'))");

        // ------------------------------------------------------------------ 3
        // RLS: the house pattern, copied verbatim from the ITP migration.
        foreach (['itp_stage_documents'] as $table) {
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
        Schema::dropIfExists('itp_stage_documents');

        Schema::table('itp_stages', function (Blueprint $table): void {
            $table->dropColumn('form_number');
        });
    }
};
