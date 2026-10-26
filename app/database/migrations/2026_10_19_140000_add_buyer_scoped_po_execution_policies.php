<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-036 stage1: nominations only, no approval/issuance or implicit PO read delegation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_execution_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->integer('version');
            $t->jsonb('finance_selectors');
            $t->jsonb('issue_selectors');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'buyer_company_id', 'version'], 'po_execution_policy_version_unique');
            $t->unique(['id', 'project_id', 'buyer_company_id'], 'po_execution_policy_basis_unique');
        });
        DB::statement("ALTER TABLE po_execution_policies ADD CONSTRAINT po_execution_policy_shape CHECK
            (version>0 AND length(btrim(reason))>0 AND jsonb_typeof(finance_selectors)='array' AND jsonb_array_length(finance_selectors)<=100
            AND jsonb_typeof(issue_selectors)='array' AND jsonb_array_length(issue_selectors)<=100)");
        DB::statement('ALTER TABLE po_execution_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE po_execution_policies FORCE ROW LEVEL SECURITY');
        $visible = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS
            (SELECT 1 FROM project_company pc WHERE pc.project_id=po_execution_policies.project_id
            AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
        DB::statement("CREATE POLICY po_execution_policy_read ON po_execution_policies FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY po_execution_policy_insert ON po_execution_policies FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM projects p WHERE p.id=po_execution_policies.project_id AND p.owner_company_id=po_execution_policies.company_id))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_po_execution_policy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_version integer; person record; selection jsonb;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    SELECT COALESCE(max(version),0) INTO current_version FROM po_execution_policies
        WHERE project_id=NEW.project_id AND buyer_company_id=NEW.buyer_company_id;
    IF NEW.version<>current_version+1 THEN RAISE EXCEPTION 'PO_EXECUTION_POLICY_STALE' USING ERRCODE='23514'; END IF;
    IF NOT EXISTS (SELECT 1 FROM companies c JOIN project_company pc ON pc.company_id=c.id
        WHERE pc.project_id=NEW.project_id AND c.id=NEW.buyer_company_id AND c.tenant_type IN ('OWN','EPC','SUB')) THEN
        RAISE EXCEPTION 'PO_EXECUTION_BUYER_INVALID' USING ERRCODE='23514';
    END IF;
    SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
        WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
        AND EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
    IF NOT FOUND THEN RAISE EXCEPTION 'PO_EXECUTION_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
    FOR selection IN SELECT value FROM jsonb_array_elements(NEW.finance_selectors||NEW.issue_selectors) LOOP
        IF NOT EXISTS (SELECT 1 FROM users u JOIN project_memberships pm ON pm.user_id=u.id
            JOIN project_company pc ON pc.company_id=u.company_id AND pc.project_id=pm.project_id
            WHERE pm.project_id=NEW.project_id AND u.is_active AND
                (selection->>'selector'='user:'||u.id::text OR EXISTS
                (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
                    AND selection->>'selector'='role:'||u.company_id::text||':'||r.code))) THEN
            RAISE EXCEPTION 'PO_EXECUTION_SELECTOR_INVALID' USING ERRCODE='23514';
        END IF;
    END LOOP;
    NEW.actor_name:=person.name; NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $$;
CREATE TRIGGER po_execution_policy_guard BEFORE INSERT ON po_execution_policies
FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_execution_policy();

CREATE OR REPLACE FUNCTION epcc_po_execution_nomination(pid uuid,aid uuid,cid uuid,bid uuid,purpose text,disc text)
RETURNS uuid LANGUAGE sql STABLE AS $$
WITH latest AS (SELECT id,finance_selectors,issue_selectors FROM po_execution_policies
    WHERE project_id=pid AND buyer_company_id=bid ORDER BY version DESC LIMIT 1)
SELECT latest.id FROM latest,users u JOIN project_memberships pm ON pm.user_id=u.id
WHERE purpose IN ('finance','issue') AND u.id=aid AND u.company_id=cid AND u.is_active AND pm.project_id=pid
    AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid
    AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND epcc_project_is_writable(pid)
    AND (disc IS NULL OR pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope,disc))
    AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
    AND EXISTS (SELECT 1 FROM project_company pc JOIN companies c ON c.id=pc.company_id
        WHERE pc.project_id=pid AND pc.company_id=bid AND c.tenant_type IN ('OWN','EPC','SUB'))
    AND EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN purpose='finance' THEN latest.finance_selectors ELSE latest.issue_selectors END) selection
        WHERE selection->>'selector'='user:'||u.id::text OR EXISTS
        (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND selection->>'selector'='role:'||cid::text||':'||r.code))
LIMIT 1
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain buyer-scoped execution nomination history.');
    }
};
