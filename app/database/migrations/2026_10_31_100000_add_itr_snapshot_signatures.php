<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 A.5b slice 2 — the formal signature layer on ITR snapshots.
 *
 * Everything up to here recorded quality evidence: welds, examinations, pack
 * results, certificates, stage sign-offs, and (slice 1) a gate that makes the
 * sign-off register load bearing. What none of it could do was say, of a
 * finished dossier, *this is the document I reviewed and here is my name on
 * it*. The dossier's own `kind` field says ITR_COMPILATION_NOT_A_CERTIFICATE,
 * and that stays true. But a compilation that nobody can sign is a draft
 * nobody can close.
 *
 * So the signature layer exists — and it is built to be consequential rather
 * than decorative, because the previous slice's whole argument was that a
 * record which gates nothing is decoration. Three rules make it so:
 *
 *   1. A SIGNATURE BINDS A DOCUMENT, AND THAT DOCUMENT MUST BE INTACT. The
 *      digest it signs is derived by the trigger from the frozen snapshot,
 *      never declared by the caller, and a snapshot whose dossier no longer
 *      matches its digest is refused: the table is append-only, so a mismatch
 *      means the document was changed underneath, and a signature over that is
 *      a signature over something else.
 *
 *   2. AN ENDORSEMENT IS A CLAIM THAT THE DOCUMENT IS FINISHED. The
 *      completeness report enumerates every gap, so ENDORSED is refused while
 *      any gap remains. The honest way past it is ENDORSED_WITH_COMMENTS,
 *      whose comments are mandatory: a dossier may be accepted with known
 *      punch items, but never silently. (Same shape as an ITP sign-off: an
 *      outcome that changes what the record means must say why.)
 *
 *   3. SIGNING IS DEFAULT-DENY BEHIND THE NOMINATION REGISTER, and the
 *      policy that authorised each signature is stamped on the row — who was
 *      allowed to say this stays auditable after they have said it.
 *
 * A second signature from one company is a new chained row naming the head,
 * never an edit: both stand, the latest is that company's word, and a stale
 * submission cannot fork one history into two.
 *
 * Declared boundaries: this makes a signature consequential, it does NOT make
 * the dossier a certificate — the `kind` is unchanged and nothing here claims
 * the work was accepted. The platform does not decide which companies must
 * sign: a contractor's QC and a client's inspector are both legitimate signers
 * and the register, not this table, decides who may. Subsystem/system scopes
 * and receipt/MTR assembly remain open.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Teach the nomination register the new action. The constraint is
        //    named (A.4d renamed it), but find it by definition anyway: a name
        //    is a promise someone can break, a definition is not.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE c name;
BEGIN
  SELECT conname INTO c FROM pg_constraint
   WHERE conrelid = 'result_recorder_policies'::regclass AND contype = 'c'
     AND pg_get_constraintdef(oid) LIKE '%action%';
  IF c IS NULL THEN
    RAISE EXCEPTION 'result_recorder_policies action CHECK not found; refusing to widen it blindly';
  END IF;
  EXECUTE format('ALTER TABLE result_recorder_policies DROP CONSTRAINT %I', c);
  ALTER TABLE result_recorder_policies ADD CONSTRAINT result_recorder_policies_action_check
    CHECK (action IN ('record_weld','record_ndt','record_pack_result','register_certificate',
                      'sign_off_stage','bind_certificate','reconcile_legacy','sign_itr_snapshot'));
END $$;
SQL);

        // 2. The signature register.
        Schema::create('itr_snapshot_signatures', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('snapshot_id');
            $t->char('digest', 64);          // trigger-derived from the snapshot
            $t->string('outcome', 24);
            $t->text('reason')->nullable();
            $t->uuid('signed_by');
            $t->timestampTz('signed_at');
            $t->uuid('signer_policy_id');    // trigger-derived: who was allowed to sign
            $t->uuid('previous_signature_id')->nullable();
            $t->uuid('request_id');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('snapshot_id')->references('id')->on('itr_snapshots')->restrictOnDelete();
            $t->foreign('signed_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'request_id'], 'itr_signature_request_unique');
            $t->unique(['project_id', 'id'], 'itr_signature_tenant_key');
            $t->index(['project_id', 'snapshot_id'], 'itr_signature_snapshot_idx');
        });

        // 3. Self-reference in a second pass: a self-FK declared inside
        //    Schema::create fails (the PK is applied after in-create FK ALTERs).
        DB::unprepared('ALTER TABLE itr_snapshot_signatures
  ADD FOREIGN KEY(previous_signature_id) REFERENCES itr_snapshot_signatures(id) ON DELETE RESTRICT;');

        // 4. Reason pairing, in the database: an endorsement with comments
        //    carries the comments, a refusal carries the cause, and a plain
        //    endorsement carries no qualification at all.
        DB::unprepared(<<<'SQL'
ALTER TABLE itr_snapshot_signatures ADD CHECK (
  outcome IN ('ENDORSED','ENDORSED_WITH_COMMENTS','REFUSED')
  AND (outcome <> 'ENDORSED') = (reason IS NOT NULL)
  AND (length(btrim(COALESCE(reason,''))) > 0) = (reason IS NOT NULL));
SQL);

        // 5. The guard's text is the file, applied before the trigger binds it.
        GuardSql::apply(['epcc_guard_itr_signature']);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_reject_itr_signature_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'ITR signatures are append-only; a later signature is a new chained row naming the head, never an edit'
    USING ERRCODE = '55000';
END; $$;

CREATE TRIGGER itr_signature_immutable BEFORE UPDATE OR DELETE ON itr_snapshot_signatures
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_itr_signature_mutation();
CREATE TRIGGER itr_signature_guard BEFORE INSERT ON itr_snapshot_signatures
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_itr_signature();

ALTER TABLE itr_snapshot_signatures ENABLE ROW LEVEL SECURITY;
ALTER TABLE itr_snapshot_signatures FORCE ROW LEVEL SECURITY;
CREATE POLICY itr_signature_read ON itr_snapshot_signatures FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = itr_snapshot_signatures.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY itr_signature_insert ON itr_snapshot_signatures FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND signed_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = itr_snapshot_signatures.project_id
                 AND pc.company_id = itr_snapshot_signatures.company_id));
SQL);

        // House rule: freeze the invoker read contract so a later migration
        // cannot widen visibility without saying so.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'itr_snapshot_signatures'::regclass AND polcmd IN ('r', '*')");
        if ($policies !== '{itr_signature_read}') {
            throw new RuntimeException('Unexpected read policy on itr_snapshot_signatures; reconcile before installing.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('ITR signatures are forward-only evidence.');
    }
};
