<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 — inspection and test RESULT records (slice 1).
 *
 * Joints stopped at FITTED and test packs carried only mutable state stamps;
 * "a notification is not an inspection result" (ADR-042…045) left the quality
 * loop open. This migration closes the recording half of it with four
 * append-only, RLS+FORCE tables:
 *
 *   joint_weld_records    who welded a fitted joint, when, by which stamp code
 *                         (WELD / REPAIR_WELD with mandatory reason)
 *   joint_ndt_results     per-joint per-method (RT/UT/MT/PT/VT) ACCEPTED or
 *                         REJECTED results with re-examination lineage
 *   test_pack_results     hydro/pneumatic results per pack (pressure, hold,
 *                         PASS/FAIL) with retest lineage
 *   material_certificates MTR/EN 10204 registry bound to heat (+lot), the
 *                         issuer always named, CDE document optional
 *
 * Provenance honesty (the ADR-031 two-mode precedent): every weld/NDT/test
 * result declares mode IN_SYSTEM or EXTERNAL_REGISTER; an external record must
 * carry performer organisation, performer name and report reference, and is
 * never presented as platform-collected. Identity follows the joints precedent:
 * technician/welder stamp codes are STRINGS (site personnel have no logins),
 * an optional users FK enriches when they do.
 *
 * Slice boundaries (declared, tested where they bind): ITP hold/witness
 * sign-offs on notification stages are slice 2; joint.state stays FITTED
 * (derived weld/NDT status is computed, never stamped); test-pack state
 * transitions are NOT yet gated on results.
 *
 * Forward-only: recorded quality history cannot be unmade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joint_weld_records', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('joint_id');
            $t->string('kind', 16);
            // Stamp code, not a users FK — same reason as joints.fitted_by_code.
            $t->string('welded_by_code', 64);
            $t->uuid('welded_by_user')->nullable();
            $t->string('weld_process', 32)->nullable();
            $t->string('consumable_lot_ref', 128)->nullable();
            $t->timestampTz('welded_at', 6);
            $t->text('reason')->nullable();
            $t->string('mode', 24)->default('IN_SYSTEM');
            $t->text('performer_org')->nullable();
            $t->text('performer_name')->nullable();
            $t->string('report_ref', 200)->nullable();
            $t->uuid('recorded_by');
            $t->uuid('request_id');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('joint_id')->references('id')->on('joints')->restrictOnDelete();
            $t->foreign('welded_by_user')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });

        Schema::create('joint_ndt_results', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('joint_id');
            $t->string('method', 8);
            $t->string('result', 8);
            $t->text('reject_reason')->nullable();
            // Re-examination lineage: points at the REJECTED result of the same joint+method.
            $t->uuid('previous_result_id')->nullable();
            $t->string('performed_by_code', 64);
            $t->uuid('performed_by_user')->nullable();
            $t->timestampTz('performed_at', 6);
            $t->string('technique_ref', 128)->nullable();
            $t->string('acceptance_criteria_ref', 128)->nullable();
            $t->string('mode', 24)->default('IN_SYSTEM');
            $t->text('performer_org')->nullable();
            $t->text('performer_name')->nullable();
            $t->string('report_ref', 200)->nullable();
            // Report file lives in the CDE document register when it exists (ADR-031: file optional).
            $t->uuid('report_document_id')->nullable();
            $t->uuid('recorded_by');
            $t->uuid('request_id');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('joint_id')->references('id')->on('joints')->restrictOnDelete();
            $t->foreign('performed_by_user')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('report_document_id')->references('id')->on('documents')->restrictOnDelete();
            $t->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });

        // Self-FK in a second pass: Laravel compiles the implied primary key
        // ALTER after in-create foreign keys, so a self-reference inside the
        // create would run before PK(id) exists (SQLSTATE 42830).
        Schema::table('joint_ndt_results', function (Blueprint $t): void {
            $t->foreign('previous_result_id')->references('id')->on('joint_ndt_results')->restrictOnDelete();
        });

        Schema::create('test_pack_results', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('test_pack_id');
            $t->string('test_type', 24);
            $t->string('medium', 32);
            $t->decimal('test_pressure', 12, 3);
            $t->string('pressure_unit', 12);
            $t->unsignedInteger('hold_duration_minutes');
            $t->timestampTz('started_at', 6);
            $t->timestampTz('completed_at', 6);
            $t->string('result', 8);
            $t->text('failure_reason')->nullable();
            // Retest lineage: points at the FAILED result of the same pack.
            $t->uuid('previous_result_id')->nullable();
            $t->string('mode', 24)->default('IN_SYSTEM');
            $t->text('performer_org')->nullable();
            $t->text('performer_name')->nullable();
            $t->string('report_ref', 200)->nullable();
            $t->uuid('recorded_by');
            $t->uuid('request_id');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('test_pack_id')->references('id')->on('piping_test_packs')->restrictOnDelete();
            $t->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });

        // Same second-pass rule as joint_ndt_results: self-FK after PK exists.
        Schema::table('test_pack_results', function (Blueprint $t): void {
            $t->foreign('previous_result_id')->references('id')->on('test_pack_results')->restrictOnDelete();
        });

        Schema::create('material_certificates', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            // The ADR decision: MTRs bind to heat (+lot when known). The heat is
            // the same string the custody/stock ledger carries — exact match, no
            // fuzzy merging; an unknown heat is its own honest bucket.
            $t->string('heat_ref', 200);
            $t->string('lot_ref', 200)->nullable();
            $t->string('certificate_type', 24);
            $t->string('certificate_ref', 200);
            // A certificate with no named issuer is fiction; the issuer is never
            // implied from the registering company.
            $t->text('issuer_name');
            $t->uuid('vendor_company_id')->nullable();
            $t->uuid('material_item_id')->nullable();
            $t->uuid('document_id')->nullable();
            $t->uuid('registered_by');
            $t->uuid('request_id');
            $t->timestampTz('registered_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('vendor_company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('material_item_id')->references('id')->on('material_items')->restrictOnDelete();
            $t->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $t->foreign('registered_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------- weld records
ALTER TABLE joint_weld_records ADD CHECK(kind IN ('WELD','REPAIR_WELD'));
ALTER TABLE joint_weld_records ADD CHECK(mode IN ('IN_SYSTEM','EXTERNAL_REGISTER'));
ALTER TABLE joint_weld_records ADD CHECK(welded_by_code ~ '[^[:space:]]' AND length(welded_by_code) <= 64);
ALTER TABLE joint_weld_records ADD CHECK((mode = 'EXTERNAL_REGISTER') = (performer_org IS NOT NULL AND performer_name IS NOT NULL AND report_ref IS NOT NULL));
ALTER TABLE joint_weld_records ADD CHECK(kind = 'WELD' OR (reason IS NOT NULL AND length(reason) <= 4000 AND reason ~ '[^[:space:]]'));

-- ------------------------------------------------------------------ NDT results
ALTER TABLE joint_ndt_results ADD CHECK(method IN ('RT','UT','MT','PT','VT'));
ALTER TABLE joint_ndt_results ADD CHECK(result IN ('ACCEPTED','REJECTED'));
ALTER TABLE joint_ndt_results ADD CHECK(mode IN ('IN_SYSTEM','EXTERNAL_REGISTER'));
ALTER TABLE joint_ndt_results ADD CHECK(performed_by_code ~ '[^[:space:]]' AND length(performed_by_code) <= 64);
ALTER TABLE joint_ndt_results ADD CHECK((result = 'REJECTED') = (reject_reason IS NOT NULL AND length(reject_reason) <= 4000 AND reject_reason ~ '[^[:space:]]'));
ALTER TABLE joint_ndt_results ADD CHECK((mode = 'EXTERNAL_REGISTER') = (performer_org IS NOT NULL AND performer_name IS NOT NULL AND report_ref IS NOT NULL));

-- -------------------------------------------------------------- test results
ALTER TABLE test_pack_results ADD CHECK(test_type IN ('HYDROSTATIC','PNEUMATIC'));
ALTER TABLE test_pack_results ADD CHECK(result IN ('PASS','FAIL'));
ALTER TABLE test_pack_results ADD CHECK(mode IN ('IN_SYSTEM','EXTERNAL_REGISTER'));
ALTER TABLE test_pack_results ADD CHECK(test_pressure > 0 AND pressure_unit ~ '[^[:space:]]' AND medium ~ '[^[:space:]]');
ALTER TABLE test_pack_results ADD CHECK(completed_at >= started_at);
ALTER TABLE test_pack_results ADD CHECK((result = 'FAIL') = (failure_reason IS NOT NULL AND length(failure_reason) <= 4000 AND failure_reason ~ '[^[:space:]]'));
ALTER TABLE test_pack_results ADD CHECK((mode = 'EXTERNAL_REGISTER') = (performer_org IS NOT NULL AND performer_name IS NOT NULL AND report_ref IS NOT NULL));

-- ------------------------------------------------------------------ certificates
ALTER TABLE material_certificates ADD CHECK(certificate_type IN ('EN10204_3_1','EN10204_3_2','MILL_MTR','OTHER'));
ALTER TABLE material_certificates ADD CHECK(heat_ref ~ '[^[:space:]]' AND certificate_ref ~ '[^[:space:]]' AND issuer_name ~ '[^[:space:]]');

-- ------------------------------------------------------------------------ RLS
-- Read: project members (the joints/material_items pattern) — quality records
-- are shared evidence inside the project, never only the recorder's trophy.
-- Write: holder company + named actor GUC + membership, insert-only.
ALTER TABLE joint_weld_records ENABLE ROW LEVEL SECURITY; ALTER TABLE joint_weld_records FORCE ROW LEVEL SECURITY;
ALTER TABLE joint_ndt_results ENABLE ROW LEVEL SECURITY; ALTER TABLE joint_ndt_results FORCE ROW LEVEL SECURITY;
ALTER TABLE test_pack_results ENABLE ROW LEVEL SECURITY; ALTER TABLE test_pack_results FORCE ROW LEVEL SECURITY;
ALTER TABLE material_certificates ENABLE ROW LEVEL SECURITY; ALTER TABLE material_certificates FORCE ROW LEVEL SECURITY;

CREATE POLICY result_weld_read ON joint_weld_records FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = joint_weld_records.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY result_weld_insert ON joint_weld_records FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND recorded_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = joint_weld_records.project_id AND pc.company_id = company_id));

CREATE POLICY result_ndt_read ON joint_ndt_results FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = joint_ndt_results.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY result_ndt_insert ON joint_ndt_results FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND recorded_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = joint_ndt_results.project_id AND pc.company_id = company_id));

CREATE POLICY result_pack_read ON test_pack_results FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = test_pack_results.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY result_pack_insert ON test_pack_results FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND recorded_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = test_pack_results.project_id AND pc.company_id = company_id));

CREATE POLICY result_mtr_read ON material_certificates FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = material_certificates.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY result_mtr_insert ON material_certificates FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND registered_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = material_certificates.project_id AND pc.company_id = company_id));

-- ------------------------------------------------------------------- triggers
CREATE OR REPLACE FUNCTION epcc_reject_result_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'inspection/test results are append-only' USING ERRCODE = '55000';
END; $$;
CREATE TRIGGER weld_records_immutable BEFORE UPDATE OR DELETE ON joint_weld_records FOR EACH ROW EXECUTE FUNCTION epcc_reject_result_mutation();
CREATE TRIGGER ndt_results_immutable BEFORE UPDATE OR DELETE ON joint_ndt_results FOR EACH ROW EXECUTE FUNCTION epcc_reject_result_mutation();
CREATE TRIGGER pack_results_immutable BEFORE UPDATE OR DELETE ON test_pack_results FOR EACH ROW EXECUTE FUNCTION epcc_reject_result_mutation();
CREATE TRIGGER mtr_records_immutable BEFORE UPDATE OR DELETE ON material_certificates FOR EACH ROW EXECUTE FUNCTION epcc_reject_result_mutation();

-- Result records are posted directly by the application, never born inside
-- another trigger's body; a nested path is a forged path.
CREATE OR REPLACE FUNCTION epcc_result_insert_path() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF pg_trigger_depth() <> 1 THEN
    RAISE EXCEPTION 'RESULT_MANUAL_DIRECT_ONLY' USING ERRCODE = '55000';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER weld_records_insert_path BEFORE INSERT ON joint_weld_records FOR EACH ROW EXECUTE FUNCTION epcc_result_insert_path();
CREATE TRIGGER ndt_results_insert_path BEFORE INSERT ON joint_ndt_results FOR EACH ROW EXECUTE FUNCTION epcc_result_insert_path();
CREATE TRIGGER pack_results_insert_path BEFORE INSERT ON test_pack_results FOR EACH ROW EXECUTE FUNCTION epcc_result_insert_path();
CREATE TRIGGER mtr_records_insert_path BEFORE INSERT ON material_certificates FOR EACH ROW EXECUTE FUNCTION epcc_result_insert_path();

CREATE OR REPLACE FUNCTION epcc_guard_joint_result() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE j joints%ROWTYPE; prev joint_ndt_results%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('joint-result:' || NEW.joint_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO j FROM joints WHERE id = NEW.joint_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RESULT_JOINT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- Honest order: fit-up, then weld, then examination. A result on a MODELLED
  -- joint is a record of something that has not physically happened.
  IF j.state <> 'FITTED' THEN
    RAISE EXCEPTION 'RESULT_JOINT_NOT_FITTED' USING ERRCODE = '23514';
  END IF;
  IF TG_TABLE_NAME = 'joint_ndt_results' THEN
    IF NOT EXISTS (SELECT 1 FROM joint_weld_records w WHERE w.joint_id = NEW.joint_id AND w.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'RESULT_NDT_NEEDS_WELD' USING ERRCODE = '23514';
    END IF;
    IF NEW.previous_result_id IS NOT NULL THEN
      SELECT * INTO prev FROM joint_ndt_results WHERE id = NEW.previous_result_id AND project_id = NEW.project_id;
      IF NOT FOUND OR prev.joint_id <> NEW.joint_id OR prev.method <> NEW.method OR prev.result <> 'REJECTED' THEN
        RAISE EXCEPTION 'RESULT_PREVIOUS_NOT_REJECTED' USING ERRCODE = '23514';
      END IF;
    END IF;
    IF NEW.report_document_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents d WHERE d.id = NEW.report_document_id AND d.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'RESULT_REPORT_DOCUMENT_NOT_VISIBLE' USING ERRCODE = '42501';
    END IF;
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER weld_records_guard BEFORE INSERT ON joint_weld_records FOR EACH ROW EXECUTE FUNCTION epcc_guard_joint_result();
CREATE TRIGGER ndt_results_guard BEFORE INSERT ON joint_ndt_results FOR EACH ROW EXECUTE FUNCTION epcc_guard_joint_result();

CREATE OR REPLACE FUNCTION epcc_guard_test_pack_result() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE p piping_test_packs%ROWTYPE; prev test_pack_results%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('pack-result:' || NEW.test_pack_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO p FROM piping_test_packs WHERE id = NEW.test_pack_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RESULT_PACK_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- No test result for a pack nobody has declared ready.
  IF p.state = 'DRAFT' THEN
    RAISE EXCEPTION 'RESULT_PACK_NOT_READY' USING ERRCODE = '23514';
  END IF;
  IF NEW.previous_result_id IS NOT NULL THEN
    SELECT * INTO prev FROM test_pack_results WHERE id = NEW.previous_result_id AND project_id = NEW.project_id;
    IF NOT FOUND OR prev.test_pack_id <> NEW.test_pack_id OR prev.result <> 'FAIL' THEN
      RAISE EXCEPTION 'RESULT_PREVIOUS_NOT_FAILED' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER pack_results_guard BEFORE INSERT ON test_pack_results FOR EACH ROW EXECUTE FUNCTION epcc_guard_test_pack_result();

CREATE OR REPLACE FUNCTION epcc_guard_material_certificate() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.registered_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  -- A cited document must exist and be visible to the registrar; a dangling
  -- evidence pointer is worse than no pointer.
  IF NEW.document_id IS NOT NULL AND NOT EXISTS (
      SELECT 1 FROM documents d WHERE d.id = NEW.document_id AND d.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'MTR_DOCUMENT_NOT_VISIBLE' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER mtr_records_guard BEFORE INSERT ON material_certificates FOR EACH ROW EXECUTE FUNCTION epcc_guard_material_certificate();
SQL);

        // Freeze the invoker read contract per table, house style.
        foreach ([
            'joint_weld_records' => '{result_weld_read}',
            'joint_ndt_results' => '{result_ndt_read}',
            'test_pack_results' => '{result_pack_read}',
            'material_certificates' => '{result_mtr_read}',
        ] as $table => $expected) {
            $policies = DB::scalar('SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = ?::regclass AND polcmd IN (\'r\', \'*\')', [$table]);
            if ($policies !== $expected) {
                throw new RuntimeException("Unexpected read policy on {$table}; reconcile before installing.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Inspection and test result history is forward-only.');
    }
};
