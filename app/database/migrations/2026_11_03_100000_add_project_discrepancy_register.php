<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * THE GAP
 *
 * A release note can refuse a purchase order line, and that refusal blocks
 * receipt. But the refusal starts no process: it is a dead end. The vendor is
 * told "not released" and there is no record anywhere of what happens next, so
 * the same material comes back on the next truck with no answer to it.
 *
 * The only TQ/NCR register that exists (2026-10-15) hangs off a pipe-schedule
 * exception request. Nothing else in the project can raise a discrepancy.
 *
 * WHY THIS SHAPE
 *
 * ONE register for the project, not one per source. A discrepancy is a project
 * fact; only its SOURCE varies. A CHECK requires exactly one source, and the
 * guard requires that whichever is set belongs to THIS project — polymorphism
 * without a project check is how one tenant's NCR starts blocking another
 * tenant's line.
 *
 * State is DERIVED from events, never declared by a column. A column saying
 * "CLOSED" that no event supports is a claim, not a record.
 *
 * The two provenance modes are carried over unchanged from the schedule slice.
 * An externally registered report is still not described as issued or signed by
 * the application.
 *
 * DERIVED, NEVER DECLARED
 *
 * `epcc_discrepancy_state(report_id)` reads the append-only event log. The
 * report row itself stores no state, so no one can write "closed" into it.
 *
 * Declared boundary: this slice records and reads. It does NOT issue, route,
 * sign, or verify, and it grants no release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_discrepancy_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id'); // recording tenant; not necessarily the responsible company
            $t->string('kind', 3);                    // TQ / NCR
            $t->string('origin', 24);                 // IN_SYSTEM / EXTERNAL_REGISTER

            // Exactly one source. The guard proves it belongs to this project.
            $t->uuid('purchase_order_item_id')->nullable();
            $t->uuid('component_id')->nullable();
            $t->uuid('pipe_schedule_request_id')->nullable();
            $t->uuid('test_pack_id')->nullable();
            $t->uuid('release_note_id')->nullable();

            // Provenance of an already-issued, already-signed external report.
            // Never an application-issued document number.
            $t->string('report_number', 160)->nullable();
            $t->string('report_revision', 64)->nullable();
            $t->string('reported_issuer', 200)->nullable();
            $t->string('reported_signer', 200)->nullable();
            $t->string('reported_signer_capacity', 200)->nullable();
            $t->timestampTz('reported_issued_at')->nullable();
            $t->timestampTz('reported_signed_at')->nullable();
            $t->string('external_register_ref', 512)->nullable();

            $t->uuid('recorded_by'); // the recorder only — never the issuer or the signer
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
            $t->foreign('component_id')->references('id')->on('components')->restrictOnDelete();
            $t->foreign('pipe_schedule_request_id')->references('id')->on('pipe_schedule_exception_requests')->restrictOnDelete();
            $t->foreign('test_pack_id')->references('id')->on('piping_test_packs')->restrictOnDelete();
            $t->foreign('release_note_id')->references('id')->on('inspection_release_notes')->restrictOnDelete();

            $t->index(['project_id', 'kind', 'created_at'], 'discrepancy_project_kind_idx');
            $t->index(['purchase_order_item_id'], 'discrepancy_line_idx');
        });

        // The event log. Append-only: a correction is a new event.
        Schema::create('project_discrepancy_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('report_id');
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('event', 24); // RAISED / ANSWERED / DISPOSED / CLOSED / EXTERNAL_VERIFIED
            $t->text('note')->nullable();
            $t->uuid('actor_id');
            $t->timestampTz('created_at')->useCurrent();

            $t->foreign('report_id')->references('id')->on('project_discrepancy_reports')->restrictOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();

            $t->index(['report_id', 'created_at'], 'discrepancy_event_report_idx');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE project_discrepancy_reports ADD CONSTRAINT discrepancy_report_shape CHECK (
    kind IN ('TQ', 'NCR')
    AND origin IN ('IN_SYSTEM', 'EXTERNAL_REGISTER')
    -- Exactly one source, so a discrepancy is never half-attached.
    AND num_nonnulls(purchase_order_item_id, component_id, pipe_schedule_request_id,
                     test_pack_id, release_note_id) = 1
    AND (
        -- IN_SYSTEM: the platform has not issued anything yet, so it may not
        -- claim a number, an issuer or a signature.
        (origin = 'IN_SYSTEM'
         AND report_number IS NULL AND report_revision IS NULL
         AND reported_issuer IS NULL AND reported_signer IS NULL
         AND reported_signer_capacity IS NULL AND reported_issued_at IS NULL
         AND reported_signed_at IS NULL AND external_register_ref IS NULL)
        OR
        -- EXTERNAL_REGISTER: the report was issued and signed OUTSIDE. The
        -- register records what was reported; it does not verify it.
        (origin = 'EXTERNAL_REGISTER'
         AND report_number IS NOT NULL AND reported_issuer IS NOT NULL
         AND reported_signer IS NOT NULL AND reported_signer_capacity IS NOT NULL
         AND external_register_ref IS NOT NULL
         AND reported_issued_at IS NOT NULL AND reported_signed_at IS NOT NULL
         AND length(btrim(report_number)) >= 2
         AND length(btrim(reported_issuer)) >= 2
         AND length(btrim(reported_signer)) >= 2
         AND length(btrim(reported_signer_capacity)) >= 2
         AND length(btrim(external_register_ref)) >= 2
         AND (report_revision IS NULL OR length(btrim(report_revision)) >= 1))
    )
);

ALTER TABLE project_discrepancy_events ADD CONSTRAINT discrepancy_event_shape CHECK (
    event IN ('RAISED', 'ANSWERED', 'DISPOSED', 'CLOSED', 'EXTERNAL_VERIFIED')
);
SQL);

        // Tenancy, and the append-only contract.
        DB::unprepared(<<<'SQL'
ALTER TABLE project_discrepancy_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE project_discrepancy_reports FORCE ROW LEVEL SECURITY;

CREATE POLICY discrepancy_report_read ON project_discrepancy_reports FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = project_discrepancy_reports.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
);

CREATE POLICY discrepancy_report_insert ON project_discrepancy_reports FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND recorded_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = project_discrepancy_reports.project_id
                 AND pc.company_id = project_discrepancy_reports.company_id)
);

ALTER TABLE project_discrepancy_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE project_discrepancy_events FORCE ROW LEVEL SECURITY;

CREATE POLICY discrepancy_event_read ON project_discrepancy_events FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = project_discrepancy_events.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
);

CREATE POLICY discrepancy_event_insert ON project_discrepancy_events FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND actor_id = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = project_discrepancy_events.project_id
                 AND pc.company_id = project_discrepancy_events.company_id)
);

CREATE OR REPLACE FUNCTION epcc_reject_discrepancy_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'Discrepancy records are append-only; a correction is a new event, never an edit'
    USING ERRCODE = '55000';
END; $$;
SQL);

        // The guard has the last word, so the trigger binds the file.
        GuardSql::apply(['epcc_guard_project_discrepancy']);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER discrepancy_report_immutable BEFORE UPDATE OR DELETE ON project_discrepancy_reports
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_discrepancy_mutation();
CREATE TRIGGER discrepancy_event_immutable BEFORE UPDATE OR DELETE ON project_discrepancy_events
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_discrepancy_mutation();
CREATE TRIGGER discrepancy_report_guard BEFORE INSERT ON project_discrepancy_reports
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_project_discrepancy();
CREATE TRIGGER discrepancy_event_guard BEFORE INSERT ON project_discrepancy_events
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_project_discrepancy();
SQL);

        // State is READ off the event log. Nothing writes it.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_discrepancy_state(pid uuid, rid uuid) RETURNS text
LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  SELECT CASE
    WHEN pid IS NULL OR rid IS NULL THEN NULL
    WHEN EXISTS (SELECT 1 FROM project_discrepancy_events e
                  WHERE e.project_id = pid AND e.report_id = rid AND e.event = 'CLOSED')
      THEN 'CLOSED'
    WHEN EXISTS (SELECT 1 FROM project_discrepancy_events e
                  WHERE e.project_id = pid AND e.report_id = rid
                    AND e.event = CASE (SELECT r.kind FROM project_discrepancy_reports r
                                         WHERE r.id = rid AND r.project_id = pid)
                                  WHEN 'TQ' THEN 'ANSWERED' ELSE 'DISPOSED' END)
      THEN 'ANSWERED'
    WHEN EXISTS (SELECT 1 FROM project_discrepancy_events e
                  WHERE e.project_id = pid AND e.report_id = rid AND e.event = 'RAISED')
      THEN 'OPEN'
    ELSE NULL
  END;
$$;
SQL);

        // The gate. A release says the material may move; a disposition says
        // the discrepancy is answered. Both are needed, and either one missing
        // holds the line — so an answered NCR does not silently unlock a line
        // that was never released, and a release does not paper over a
        // discrepancy nobody has answered.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_po_release_status(pid uuid)
RETURNS text LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
 WITH per_line AS (
   SELECT DISTINCT ON (purchase_order_item_id) purchase_order_item_id, outcome
     FROM inspection_release_notes
    WHERE purchase_order_id = pid
    ORDER BY purchase_order_item_id, sequence DESC
 ),
 unresolved AS (
   SELECT 1
     FROM project_discrepancy_reports d
    WHERE d.purchase_order_item_id IN (SELECT id FROM purchase_order_items WHERE purchase_order_id = pid)
      AND epcc_discrepancy_state(d.project_id, d.id) = 'OPEN'
 ) SELECT CASE
   WHEN EXISTS (SELECT 1 FROM per_line WHERE outcome = 'NOT_RELEASED')
     OR EXISTS (SELECT 1 FROM unresolved) THEN 'BLOCKED'
   WHEN EXISTS (SELECT 1 FROM per_line WHERE outcome IN ('RELEASED','RELEASED_WITH_HOLDS')) THEN 'RELEASED'
   ELSE 'NOT_REQUIRED'
 END
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_discrepancy_state(uuid, uuid)');
        DB::unprepared('DROP TRIGGER IF EXISTS discrepancy_event_guard ON project_discrepancy_events');
        DB::unprepared('DROP TRIGGER IF EXISTS discrepancy_report_guard ON project_discrepancy_reports');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_guard_project_discrepancy()');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_reject_discrepancy_mutation()');
        Schema::dropIfExists('project_discrepancy_events');
        Schema::dropIfExists('project_discrepancy_reports');
    }
};
