<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review & comment sheet (SRS FR-ENG-06, journey 3).
 *
 * Comments hang off a REVISION, not a document: "Rev B closed 14 comments" is
 * the sentence the whole cycle exists to produce, and a comment raised against
 * Rev A must stay attached to Rev A forever.
 *
 * WHY THIS TABLE BREAKS THE TENANT PATTERN
 * Every other tenant table means "rows my company owns". A comment has TWO
 * companies with a legitimate claim on it:
 *
 *   company_id     the REVIEWER who wrote it (PMC, TPI, the owner)
 *   originator_id  the company that must answer it (the EPC contractor)
 *
 * The reviewer writes the comment; the originator writes the disposition. If
 * the policy keyed on `company_id` alone, the contractor could not answer the
 * comments raised against its own drawing - which is the entire workflow.
 * So `document_review_comments` is writable by either party, and each column
 * is guarded in the service rather than by the row policy.
 *
 * WHY NOT ONE ROW PER "COMMENT SHEET"
 * A sheet is a VIEW over the comments of a revision, not a thing in its own
 * right. Modelling the sheet as a row invites a second source of truth about
 * which comments are on it, and they drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_review_comments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->uuid('revision_id');
            $t->uuid('project_id');

            // The reviewing company (tenant column for writes).
            $t->uuid('company_id');
            // The company that owns the deliverable and must respond.
            $t->uuid('originator_company_id');

            // Sequence within the revision, so a sheet reads 1..n and can be
            // cited in a meeting: "comment 7 on Rev B".
            $t->unsignedInteger('seq');

            $t->string('category', 16);          // HOLD, CODE1..CODE4, INFO
            $t->string('reference', 128)->nullable(); // sheet/zone/tag the comment is about
            $t->text('body');                    // written in whatever language the reviewer uses

            $t->uuid('raised_by')->nullable();
            $t->timestampTz('raised_at')->nullable();

            $t->string('disposition', 16)->default('PENDING');
            $t->text('disposition_note')->nullable();
            $t->uuid('disposed_by')->nullable();
            $t->timestampTz('disposed_at')->nullable();

            $t->timestampsTz();

            $t->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $t->foreign('revision_id')->references('id')->on('document_revisions')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('originator_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('raised_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('disposed_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['revision_id', 'seq']);
            $t->index(['revision_id', 'disposition']);
            $t->index(['project_id', 'category']);
        });

        DB::statement('ALTER TABLE document_review_comments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_review_comments FORCE ROW LEVEL SECURITY');

        // Reading: any participant on the project may read the sheet. A review
        // cycle that hides comments from the other reviewers produces
        // contradictory comments on the same drawing - the classic reason
        // reviews go around three times instead of once.
        //
        // Writing: the reviewer (company_id) or the originator
        // (originator_company_id), and only on a project both participate in.
        DB::statement(<<<'SQL'
            CREATE POLICY document_review_comments_tenant_isolation ON document_review_comments
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR document_review_comments.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = document_review_comments.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = document_review_comments.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR originator_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = document_review_comments.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS document_review_comments_tenant_isolation ON document_review_comments');
        Schema::dropIfExists('document_review_comments');
    }
};
