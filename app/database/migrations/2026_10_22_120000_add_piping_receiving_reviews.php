<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Receiving observations only. No custody/source/stock transition, QA acceptance or GRN. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_receiving_reviews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('custody_id')->constrained('piping_custody_records')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('piping_receipt_author_policies')->restrictOnDelete();
            $t->uuid('previous_review_id')->nullable();
            $t->uuid('request_id');
            $t->integer('sequence');
            $t->string('actor_name');
            $t->string('custody_fingerprint', 64);
            $t->timestampTz('reviewed_at');
            $t->string('observed_result', 40);
            $t->text('findings');
            $t->timestampTz('created_at');
            $t->unique(['custody_id', 'sequence']);
            $t->unique(['project_id', 'request_id']);
        });
        Schema::table('piping_receiving_reviews', fn (Blueprint $t) => $t->foreign('previous_review_id')->references('id')->on('piping_receiving_reviews')->restrictOnDelete());
        DB::unprepared(<<<'SQL'
ALTER TABLE piping_receiving_reviews ADD CONSTRAINT piping_receiving_review_shape CHECK (
 sequence>0 AND char_length(custody_fingerprint)=64 AND isfinite(reviewed_at)
 AND observed_result IN ('NO_NONCONFORMITY_OBSERVED','NONCONFORMITY_OBSERVED','INCOMPLETE')
 AND char_length(findings) BETWEEN 1 AND 4000 AND findings ~ '[^[:space:]]'
);
ALTER TABLE piping_receiving_reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_receiving_reviews FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_receiving_review_read ON piping_receiving_reviews FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM piping_custody_records c WHERE c.id=piping_receiving_reviews.custody_id AND c.project_id=piping_receiving_reviews.project_id)
);
CREATE POLICY piping_receiving_review_insert ON piping_receiving_reviews FOR INSERT WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND reviewed_by=NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 AND epcc_project_is_writable(project_id)
 AND policy_id=epcc_piping_receipt_policy(project_id,reviewed_by,company_id,'receiving_qc')
 AND EXISTS(SELECT 1 FROM piping_custody_records c WHERE c.id=piping_receiving_reviews.custody_id AND c.project_id=piping_receiving_reviews.project_id AND c.created_by<>piping_receiving_reviews.reviewed_by)
);
CREATE OR REPLACE FUNCTION epcc_piping_custody_fingerprint(rid uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT encode(sha256(convert_to((
  (to_jsonb(c)-'received_at'-'created_at') || jsonb_build_object(
   'received_at',to_char(c.received_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US'),
   'created_at',to_char(c.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US'))
 )::text,'UTF8')),'hex') FROM piping_custody_records c WHERE c.id=rid
$$;
CREATE OR REPLACE FUNCTION epcc_guard_piping_receiving_review() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE receipt piping_custody_records%ROWTYPE; head uuid; serial integer; person text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id)
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.reviewed_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_SCOPE' USING ERRCODE='42501'; END IF;
 IF NEW.policy_id IS NULL OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.reviewed_by,NEW.company_id,'receiving_qc') THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_NOMINATION' USING ERRCODE='42501'; END IF;
 SELECT * INTO receipt FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_RECEIPT' USING ERRCODE='42501'; END IF;
 -- Parent custody is immutable. FOR SHARE would require an UPDATE RLS policy; do not weaken it.
 -- Refresh personnel/entitlement/source visibility after the parent lookup in new SPI snapshots.
 IF NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.reviewed_by,NEW.company_id,'receiving_qc') THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_NOMINATION' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id) THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_RECEIPT' USING ERRCODE='42501'; END IF;
 IF receipt.created_by=NEW.reviewed_by THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_SELF_REVIEW' USING ERRCODE='23514'; END IF;
 SELECT id,sequence INTO head,serial FROM piping_receiving_reviews WHERE custody_id=NEW.custody_id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NEW.previous_review_id IS DISTINCT FROM head THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_STALE' USING ERRCODE='23514'; END IF;
 IF serial=2147483647 THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_STALE' USING ERRCODE='23514'; END IF;
 NEW.sequence:=COALESCE(serial,0)+1;
 NEW.custody_fingerprint:=epcc_piping_custody_fingerprint(NEW.custody_id);
 SELECT name INTO person FROM users WHERE id=NEW.reviewed_by AND company_id=NEW.company_id AND is_active;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_NOMINATION' USING ERRCODE='42501'; END IF;
 NEW.actor_name:=person; NEW.created_at:=clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER piping_receiving_review_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_receiving_reviews
 FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_receiving_review();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Receiving QC evidence is forward-only.');
    }
};
