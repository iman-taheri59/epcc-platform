<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-041 accepted independent project nominations; no delegated PO reading. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_author_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->integer('version');
            $t->jsonb('selectors');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'version']);
            $t->unique(['id', 'project_id']);
        });
        DB::statement("ALTER TABLE inspection_author_policies ADD CONSTRAINT inspection_author_policy_shape CHECK
            (version > 0 AND jsonb_typeof(selectors)='array' AND jsonb_array_length(selectors)<=100 AND length(btrim(reason))>0)");
        DB::statement('ALTER TABLE inspection_author_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inspection_author_policies FORCE ROW LEVEL SECURITY');
        $visible = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=inspection_author_policies.project_id
            AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
        DB::statement("CREATE POLICY inspection_author_policy_read ON inspection_author_policies FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY inspection_author_policy_insert ON inspection_author_policies FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM projects p WHERE p.id=inspection_author_policies.project_id AND p.owner_company_id=inspection_author_policies.company_id))");
        // No UPDATE/DELETE policy: a replacement is a new version, not an edit.
        Schema::table('material_inspections', function (Blueprint $t): void {
            $t->uuid('entry_policy_id')->nullable();
            $t->string('recorded_actor_name')->nullable();
            $t->foreign(['entry_policy_id', 'project_id'], 'inspection_entry_policy_fk')
                ->references(['id', 'project_id'])->on('inspection_author_policies')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_inspection_author_policy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_version integer; person record;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('inspection-entry:' || NEW.project_id::text, 0));
    SELECT COALESCE(max(version),0) INTO current_version FROM inspection_author_policies WHERE project_id=NEW.project_id;
    IF NEW.version<>current_version+1 THEN
        RAISE EXCEPTION 'INSPECTION_AUTHOR_POLICY_STALE' USING ERRCODE='23514';
    END IF;
    SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
        WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
        AND EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
    IF NOT FOUND THEN RAISE EXCEPTION 'INSPECTION_AUTHOR_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
    NEW.actor_name:=person.name;
    NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $$;
CREATE TRIGGER inspection_author_policy_guard BEFORE INSERT ON inspection_author_policies
FOR EACH ROW EXECUTE FUNCTION epcc_guard_inspection_author_policy();

CREATE OR REPLACE FUNCTION epcc_inspection_author_policy(pid uuid, aid uuid, cid uuid, disc text)
RETURNS uuid LANGUAGE sql STABLE AS $$
    WITH latest AS (SELECT id,selectors FROM inspection_author_policies WHERE project_id=pid ORDER BY version DESC LIMIT 1)
    SELECT latest.id FROM latest, users u JOIN project_memberships pm ON pm.user_id=u.id
    WHERE u.id=aid AND u.company_id=cid AND u.is_active AND pm.project_id=pid
        AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid
        AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid
        AND epcc_project_is_writable(pid)
        AND EXISTS (SELECT 1 FROM companies c JOIN plans pl ON pl.id=c.plan_id JOIN projects pr ON pr.id=pid
            WHERE c.id=cid AND c.tenant_type IN ('OWN','PMC','MC','TPI') AND (jsonb_exists(pl.modules::jsonb,'proc') OR jsonb_exists(pl.modules::jsonb,'*'))
            AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*')))
        AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
        AND (pm.discipline_scope IS NULL OR (disc IS NOT NULL AND jsonb_exists(pm.discipline_scope,disc)))
        AND EXISTS (SELECT 1 FROM jsonb_array_elements(latest.selectors) selection
            WHERE selection->>'selector'='user:' || u.id::text
            OR EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
                AND selection->>'selector'='role:' || cid::text || ':' || r.code))
    LIMIT 1
$$;


CREATE OR REPLACE FUNCTION epcc_guard_inspection_entry() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE disc text; person_name text;
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') AND OLD.entry_policy_id IS NOT NULL THEN
        RAISE EXCEPTION 'INSPECTION_ENTRY_IMMUTABLE' USING ERRCODE='23514';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    IF TG_OP='UPDATE' AND NEW.entry_policy_id IS NOT NULL THEN
        RAISE EXCEPTION 'INSPECTION_NO_LEGACY_BACKFILL' USING ERRCODE='23514';
    END IF;
    IF NEW.entry_policy_id IS NOT NULL THEN
        PERFORM pg_advisory_xact_lock(hashtextextended('inspection-entry:' || NEW.project_id::text,0));
        IF NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
            OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
            RAISE EXCEPTION 'INSPECTION_ENTRY_SCOPE' USING ERRCODE='42501';
        END IF;
        SELECT p.discipline INTO disc FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.project_id=NEW.project_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'INSPECTION_PO_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
        IF NEW.entry_policy_id IS DISTINCT FROM epcc_inspection_author_policy(NEW.project_id,NEW.inspected_by,NEW.company_id,disc) THEN
            RAISE EXCEPTION 'INSPECTION_AUTHOR_NOT_NOMINATED' USING ERRCODE='42501';
        END IF;
        SELECT name INTO person_name FROM users WHERE id=NEW.inspected_by AND company_id=NEW.company_id AND is_active;
        IF NOT FOUND THEN RAISE EXCEPTION 'INSPECTION_ACTOR_INVALID' USING ERRCODE='42501'; END IF;
        IF NEW.result NOT IN ('PASS','FAIL','CONDITIONAL') OR NEW.stage NOT IN ('WORKS','PRE_SHIPMENT','ON_ARRIVAL') THEN
            RAISE EXCEPTION 'INSPECTION_ENTRY_VERDICT' USING ERRCODE='23514';
        END IF;
        IF NEW.result <> 'PASS' AND length(btrim(COALESCE(NEW.findings_en,'')))=0 AND length(btrim(COALESCE(NEW.findings_fa,'')))=0 THEN
            RAISE EXCEPTION 'INSPECTION_FINDINGS_REQUIRED' USING ERRCODE='23514';
        END IF;
        NEW.recorded_actor_name:=person_name;
        NEW.created_at:=CURRENT_TIMESTAMP;
        NEW.updated_at:=CURRENT_TIMESTAMP;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER inspection_entry_guard BEFORE INSERT OR UPDATE OR DELETE ON material_inspections
FOR EACH ROW EXECUTE FUNCTION epcc_guard_inspection_entry();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Inspection author history and attributed records are forward-only.');
    }
};
