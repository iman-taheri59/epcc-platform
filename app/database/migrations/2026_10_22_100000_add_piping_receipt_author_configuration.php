<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-046 owner-only configuration. No stock/source table or existing permission is changed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_receipt_author_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('custodian_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $t->jsonb('custodian_snapshot')->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->integer('version');
            $t->boolean('suspended');
            $t->jsonb('actions');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'version']);
        });
        DB::statement("ALTER TABLE piping_receipt_author_policies ADD CONSTRAINT piping_receipt_policy_shape CHECK
            (version>0 AND char_length(reason) BETWEEN 1 AND 4000 AND reason ~ '[^[:space:]]'
            AND jsonb_typeof(actions)='object'
            AND ((suspended AND custodian_company_id IS NULL AND custodian_snapshot IS NULL)
            OR (NOT suspended AND custodian_company_id IS NOT NULL AND jsonb_typeof(custodian_snapshot)='object')))");
        DB::statement('ALTER TABLE piping_receipt_author_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE piping_receipt_author_policies FORCE ROW LEVEL SECURITY');
        $ownerScope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND EXISTS(SELECT 1 FROM projects p WHERE p.id=piping_receipt_author_policies.project_id AND p.owner_company_id=piping_receipt_author_policies.company_id)
            AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=piping_receipt_author_policies.project_id AND pc.company_id=piping_receipt_author_policies.company_id)";
        DB::statement("CREATE POLICY piping_receipt_policy_read ON piping_receipt_author_policies FOR SELECT USING ({$ownerScope})");
        DB::statement("CREATE POLICY piping_receipt_policy_insert ON piping_receipt_author_policies FOR INSERT WITH CHECK ({$ownerScope} AND epcc_project_is_writable(project_id))");
        // No UPDATE/DELETE policy, and no participant-wide configuration-directory disclosure.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_piping_receipt_policy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE latest integer; person text; action_key text; entry jsonb; c record;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:' || NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id AND owner_company_id=NEW.company_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) THEN
  RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_PROJECT' USING ERRCODE='42501'; END IF;
 SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
 JOIN companies co ON co.id=u.company_id JOIN plans pl ON pl.id=co.plan_id JOIN projects p ON p.id=pm.project_id
 WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
 AND (jsonb_exists(pl.modules::jsonb,'proc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,p.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'))
 AND EXISTS(SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
 AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
 SELECT COALESCE(max(version),0) INTO latest FROM piping_receipt_author_policies WHERE project_id=NEW.project_id;
 IF latest=2147483647 OR NEW.version<>latest+1 THEN RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_STALE' USING ERRCODE='23514'; END IF;
 IF jsonb_typeof(NEW.actions) IS DISTINCT FROM 'object'
 OR (SELECT count(*) FROM jsonb_object_keys(NEW.actions))<>4 THEN
  RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_ACTIONS' USING ERRCODE='23514'; END IF;
 FOREACH action_key IN ARRAY ARRAY['custody_mrr','receiving_qc','acceptance_qa','post_grn'] LOOP
  IF jsonb_typeof(NEW.actions->action_key) IS DISTINCT FROM 'array' THEN
   RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_ACTIONS' USING ERRCODE='23514'; END IF;
  IF jsonb_array_length(NEW.actions->action_key)>100
   OR (NEW.suspended AND jsonb_array_length(NEW.actions->action_key)<>0)
   OR (NOT NEW.suspended AND jsonb_array_length(NEW.actions->action_key)=0) THEN
   RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_ACTIONS' USING ERRCODE='23514'; END IF;
  FOR entry IN SELECT value FROM jsonb_array_elements(NEW.actions->action_key) LOOP
   IF jsonb_typeof(entry) IS DISTINCT FROM 'object' THEN
    RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_SELECTOR' USING ERRCODE='23514'; END IF;
   IF (SELECT count(*) FROM jsonb_object_keys(entry))<>3
    OR jsonb_typeof(entry->'selector') IS DISTINCT FROM 'string'
    OR jsonb_typeof(entry->'company_id') IS DISTINCT FROM 'string'
    OR jsonb_typeof(entry->'label') IS DISTINCT FROM 'string' THEN
    RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_SELECTOR' USING ERRCODE='23514'; END IF;
  END LOOP;
 END LOOP;
 IF NEW.suspended THEN NEW.custodian_company_id:=NULL; NEW.custodian_snapshot:=NULL;
 ELSE
  SELECT co.code,co.name_en,co.name_fa INTO c FROM companies co JOIN plans pl ON pl.id=co.plan_id
  JOIN project_company pc ON pc.company_id=co.id JOIN projects p ON p.id=pc.project_id
  WHERE co.id=NEW.custodian_company_id AND pc.project_id=NEW.project_id
   AND (jsonb_exists(pl.modules::jsonb,'proc') OR jsonb_exists(pl.modules::jsonb,'*'))
   AND (jsonb_exists(pl.standards_packs::jsonb,p.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'));
  IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_RECEIPT_POLICY_CUSTODIAN' USING ERRCODE='42501'; END IF;
  NEW.custodian_snapshot:=jsonb_build_object('code',c.code,'name_en',c.name_en,'name_fa',c.name_fa);
 END IF;
 NEW.actor_name:=person; NEW.created_at:=clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER piping_receipt_policy_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_receipt_author_policies
FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_receipt_policy();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Receipt nomination history is forward-only.');
    }
};
