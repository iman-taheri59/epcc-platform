<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ITP is itself a project document, and the inspection report forms are
 * its attachments.
 *
 * WHAT WAS WRONG
 * --------------
 * The ITP was built with its own `number` and its own DRAFT/ACTIVE/SUPERSEDED
 * lifecycle, sitting beside the document register rather than inside it. That
 * is a parallel registry, and it has the failure mode every parallel registry
 * has:
 *
 *   - the ITP does not appear in the document register, so it is absent from
 *     the transmittal, the deliverables list and the client's MDR;
 *   - two numbering schemes drift, and the number on the paper stops matching
 *     the number in the system;
 *   - the ITP escapes CDE governance — the WIP / SHARED / PUBLISHED states,
 *     the suitability codes and the review cycle that every other deliverable
 *     is subject to.
 *
 * An ITP is a deliverable. It is issued for review, commented on, revised and
 * published exactly like a datasheet.
 *
 * WHAT THIS DOES
 * --------------
 * `itps.document_id` links each plan to its register entry. The register owns
 * the number, the revisions and the CDE state; the `itps` row keeps only what
 * is specific to being an inspection plan — the discipline, the stages and the
 * gate.
 *
 * It is NULLABLE on purpose. A plan copied from the library before anybody has
 * decided its document number must still exist, and an import from a legacy
 * project may never have had one. Registration is a step, not a precondition.
 *
 * WHY THE NUMBER IS NOT SIMPLY DROPPED
 * ------------------------------------
 * `itps.number` stays as the working label so nothing that already reads it
 * breaks, but once a plan is registered the register's number is authoritative
 * and `Itp::displayNumber()` prefers it. Deleting the column would be a bigger
 * change than this problem justifies, and a plan may legitimately be worked on
 * before it is registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itps', function (Blueprint $table): void {
            /*
             * nullOnDelete, not cascade: deleting the register entry must not
             * delete the inspection plan and its signed records with it. The
             * plan becomes unregistered, which is visible and fixable; silently
             * destroying signed evidence is not.
             */
            $table->foreignUuid('document_id')->nullable()->after('company_id')
                ->constrained('documents')->nullOnDelete();
        });

        // One document is the register entry for at most one ITP. Without this
        // two plans could claim the same number through the back door, which is
        // precisely the drift this migration exists to stop.
        DB::statement('CREATE UNIQUE INDEX itps_one_per_document ON itps (document_id) WHERE document_id IS NOT NULL');

        DB::statement("COMMENT ON COLUMN itps.document_id IS
            'The register entry for this plan. The register owns the number, revisions and CDE state.'");

        DB::statement("COMMENT ON COLUMN itps.number IS
            'Working label. Once document_id is set the register number is authoritative - see Itp::displayNumber().'");

        /*
         * The inspection report forms attach to the ITP as a document, not to
         * individual rows.
         *
         * `itp_stage_documents` with usage=FORM says WHICH form a given row
         * uses, and that stays: a 40-row ITP does not hand the inspector the
         * same form forty times. But the forms themselves travel WITH the plan
         * as its annexes, which is how a real ITP is issued — the plan, then
         * Annex A, Annex B, each a blank form.
         *
         * So: a link table from the ITP to the documents that are its annexes,
         * carrying the annex label and the order they are bound in.
         */
        Schema::create('itp_annexes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained();
            $table->foreignUuid('itp_id')->constrained('itps')->cascadeOnDelete();

            // restrictOnDelete: an annex that an issued ITP depends on cannot
            // be deleted out from under it.
            $table->foreignUuid('document_id')->constrained('documents')->restrictOnDelete();

            // "A", "B", "1" - as printed on the cover sheet.
            $table->string('label', 16)->nullable();

            // Binding order. Not derived from the label, because clients number
            // annexes in ways that do not sort (A, B, B1, C).
            $table->integer('sequence')->default(10);

            /*
             * FORM       a blank inspection report form
             * PROCEDURE  a method statement or NDE procedure issued with the plan
             * REFERENCE  anything else bound in for the inspector's convenience
             */
            $table->string('kind', 16)->default('FORM');

            $table->timestamps();

            $table->unique(['itp_id', 'document_id']);
            $table->index(['itp_id', 'sequence']);
        });

        DB::statement("ALTER TABLE itp_annexes ADD CONSTRAINT itp_annexes_kind_known
            CHECK (kind IN ('FORM','PROCEDURE','REFERENCE'))");

        foreach (['itp_annexes'] as $table) {
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
        Schema::dropIfExists('itp_annexes');

        DB::statement('DROP INDEX IF EXISTS itps_one_per_document');

        Schema::table('itps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
