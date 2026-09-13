<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transmittal management (SRS FR-ENG-05, acceptance journey 3).
 *
 * A transmittal is the FORMAL RECORD that documents were issued to named
 * parties on a named date. On an EPCC contract it is the evidence in a delay
 * claim: "you had the IFC drawing on 12 Mordad" is either provable or it is
 * not, and the transmittal is the proof.
 *
 * THREE TABLES, AND WHY
 *
 *   transmittals             the covering letter: who sent it, when, why
 *   transmittal_recipients   one row per receiving COMPANY, each with its own
 *                            acknowledgement - a transmittal to four parties
 *                            is acknowledged four separate times
 *   transmittal_items        one row per REVISION sent
 *
 * ITEMS POINT AT A REVISION, NEVER AT A DOCUMENT
 * "We sent you Rev B" must stay true after Rev C exists. Pointing at the
 * document would silently rewrite history every time the drawing was
 * superseded - the exact failure the register was built to prevent.
 *
 * IMMUTABILITY
 * Once issued, the contents cannot change: no adding a document to a letter
 * that has already gone out. Enforced in the service, and the reason `status`
 * exists at all (DRAFT -> ISSUED).
 *
 * WHAT IS DERIVED, NOT STORED
 * "Overdue" is computed from `due_at` and the acknowledgement, never written
 * to a column. A stored overdue flag is wrong the moment the clock passes it
 * and nobody has run the job that updates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmittals', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The SENDING company. This is the tenant column.
            $t->uuid('company_id');

            // {PROJECT}-TR-{SEQ:4}, allocated per project.
            $t->string('number', 64);
            $t->unsignedInteger('sequence');

            $t->string('subject_en', 512);
            $t->string('subject_fa', 512);
            // Why it was sent: FOR_REVIEW, FOR_APPROVAL, FOR_CONSTRUCTION,
            // FOR_INFORMATION. Drives the acknowledgement expectation.
            $t->string('purpose', 32);

            $t->string('status', 16)->default('DRAFT'); // DRAFT | ISSUED
            $t->text('notes')->nullable();

            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at')->nullable();
            // When acknowledgement is expected by. Nullable: an information-only
            // transmittal need not be acknowledged at all.
            $t->timestampTz('due_at')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('issued_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'number']);
            $t->index(['project_id', 'status']);
        });

        // NOTE: issued_at is denormalised onto both child tables (see the
        // trigger below) purely so their RLS policies can gate on it without
        // reading the parent and triggering mutual policy recursion.
        Schema::create('transmittal_recipients', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('transmittal_id');
            $t->uuid('project_id');
            // Denormalised sender, so the RLS policy can let the SENDER see its
            // own recipient rows without joining back to transmittals.
            $t->uuid('company_id');
            // The receiving company.
            $t->uuid('recipient_company_id');

            // Mirror of transmittals.issued_at, maintained by trigger.
            $t->timestampTz('issued_at')->nullable();

            $t->timestampTz('acknowledged_at')->nullable();
            $t->uuid('acknowledged_by')->nullable();
            $t->text('acknowledgement_note')->nullable();

            $t->timestampsTz();

            $t->foreign('transmittal_id')->references('id')->on('transmittals')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('recipient_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();

            // The same company cannot be on one letter twice.
            $t->unique(['transmittal_id', 'recipient_company_id']);
            $t->index(['recipient_company_id', 'acknowledged_at']);
        });

        Schema::create('transmittal_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('transmittal_id');
            $t->uuid('project_id');
            $t->uuid('company_id');   // sender, for RLS
            $t->uuid('document_id');
            $t->uuid('revision_id');

            // Snapshot of what was true AT ISSUE. Kept even though the revision
            // is joinable: a transmittal must still read correctly if the
            // revision's state moves on afterwards, and this is what gets
            // printed on the sheet and written into the ZIP manifest.
            $t->string('number_at_issue', 128);
            $t->string('revision_at_issue', 16);
            $t->string('state_at_issue', 16);
            $t->string('suitability_at_issue', 8)->nullable();

            // Mirror of transmittals.issued_at, maintained by trigger.
            $t->timestampTz('issued_at')->nullable();

            $t->timestampsTz();

            $t->foreign('transmittal_id')->references('id')->on('transmittals')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $t->foreign('revision_id')->references('id')->on('document_revisions')->cascadeOnDelete();

            // The same revision twice on one letter is a mistake, not an intent.
            $t->unique(['transmittal_id', 'revision_id']);
        });

        // ---- issued_at propagation ----------------------------------------
        // The children carry a copy of the parent's issued_at so that their RLS
        // policies can gate on it locally. Doing this in a trigger rather than
        // in the service keeps it true for the importer, a bulk update, or a
        // DBA acting by hand - the guarantee belongs to the database.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transmittal_sync_issued_at() RETURNS trigger AS $$
            BEGIN
                IF NEW.issued_at IS DISTINCT FROM OLD.issued_at THEN
                    UPDATE transmittal_recipients
                       SET issued_at = NEW.issued_at
                     WHERE transmittal_id = NEW.id;
                    UPDATE transmittal_items
                       SET issued_at = NEW.issued_at
                     WHERE transmittal_id = NEW.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER;

            CREATE TRIGGER transmittals_sync_issued_at
                AFTER UPDATE ON transmittals
                FOR EACH ROW EXECUTE FUNCTION transmittal_sync_issued_at();
        SQL);

        // A child inserted onto an ALREADY issued letter must inherit the stamp;
        // otherwise it would be invisible to the very people it was sent to.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transmittal_child_inherit_issued_at() RETURNS trigger AS $$
            BEGIN
                IF NEW.issued_at IS NULL THEN
                    SELECT t.issued_at INTO NEW.issued_at
                      FROM transmittals t
                     WHERE t.id = NEW.transmittal_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER;

            CREATE TRIGGER transmittal_recipients_inherit_issued_at
                BEFORE INSERT ON transmittal_recipients
                FOR EACH ROW EXECUTE FUNCTION transmittal_child_inherit_issued_at();

            CREATE TRIGGER transmittal_items_inherit_issued_at
                BEFORE INSERT ON transmittal_items
                FOR EACH ROW EXECUTE FUNCTION transmittal_child_inherit_issued_at();
        SQL);

        // ---- RLS ----------------------------------------------------------
        // Visibility here is neither "my rows" nor "all participants": a
        // transmittal is correspondence, and correspondence is visible to the
        // SENDER, its RECIPIENTS, and the employer. A contractor must not read
        // a letter between the PMC and a rival contractor.
        foreach (['transmittals', 'transmittal_recipients', 'transmittal_items'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        // transmittals: sender, any recipient, or the employer.
        DB::statement(<<<'SQL'
            CREATE POLICY transmittals_tenant_isolation ON transmittals
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR transmittals.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        -- A DRAFT is unsent correspondence. Until it is issued the
                        -- sender may still add or remove recipients, revise the item
                        -- list, or abandon it entirely, so nobody outside the sending
                        -- company may see it - not the addressees, not the employer.
                        transmittals.issued_at IS NOT NULL
                        AND (
                            EXISTS (
                                SELECT 1 FROM transmittal_recipients tr
                                WHERE tr.transmittal_id = transmittals.id
                                  AND tr.recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                            OR EXISTS (
                                SELECT 1 FROM projects p
                                WHERE p.id = transmittals.project_id
                                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = transmittals.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // recipients: readable by the sender, by the recipient named on the
        // row, and by the employer. WITH CHECK allows the SENDER to create the
        // row and the RECIPIENT to update it (that is the acknowledgement).
        DB::statement(<<<'SQL'
            CREATE POLICY transmittal_recipients_tenant_isolation ON transmittal_recipients
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR transmittal_recipients.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        -- Denormalised from the parent by trigger. A policy on a
                        -- child MUST NOT select from the parent: the parent's own
                        -- policy reads this table, and PostgreSQL aborts the pair
                        -- with "infinite recursion detected in policy".
                        transmittal_recipients.issued_at IS NOT NULL
                        AND (
                            recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            OR EXISTS (
                                SELECT 1 FROM projects p
                                WHERE p.id = transmittal_recipients.project_id
                                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                    )
                )
            )
            WITH CHECK (
                (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = transmittal_recipients.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // items: follow the letter they belong to.
        DB::statement(<<<'SQL'
            CREATE POLICY transmittal_items_tenant_isolation ON transmittal_items
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR transmittal_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        transmittal_items.issued_at IS NOT NULL
                        AND (
                            EXISTS (
                                SELECT 1 FROM transmittal_recipients tr
                                WHERE tr.transmittal_id = transmittal_items.transmittal_id
                                  AND tr.recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                            OR EXISTS (
                                SELECT 1 FROM projects p
                                WHERE p.id = transmittal_items.project_id
                                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = transmittal_items.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS transmittals_sync_issued_at ON transmittals;
            DROP TRIGGER IF EXISTS transmittal_recipients_inherit_issued_at ON transmittal_recipients;
            DROP TRIGGER IF EXISTS transmittal_items_inherit_issued_at ON transmittal_items;
            DROP FUNCTION IF EXISTS transmittal_sync_issued_at();
            DROP FUNCTION IF EXISTS transmittal_child_inherit_issued_at();
        SQL);

        foreach (['transmittal_items', 'transmittal_recipients', 'transmittals'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            Schema::dropIfExists($table);
        }
    }
};
