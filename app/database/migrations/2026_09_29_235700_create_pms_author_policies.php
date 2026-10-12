<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-008 §17–18: no implied author grants; old content/history remain intact. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pms_author_policies', function (Blueprint $t): void {
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
        DB::statement("ALTER TABLE pms_author_policies ADD CONSTRAINT pms_author_policy_shape CHECK
            (version > 0 AND jsonb_typeof(selectors)='array' AND jsonb_array_length(selectors)<=100 AND length(btrim(reason))>0)");
        DB::statement('ALTER TABLE pms_author_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pms_author_policies FORCE ROW LEVEL SECURITY');
        $visible = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=pms_author_policies.project_id
            AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
        DB::statement("CREATE POLICY pms_author_policy_read ON pms_author_policies FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY pms_author_policy_insert ON pms_author_policies FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM projects p WHERE p.id=pms_author_policies.project_id AND p.owner_company_id=pms_author_policies.company_id))");
        // No UPDATE/DELETE policy: a replacement is a new version, not an edit.
        Schema::table('pms_entry_events', function (Blueprint $t): void {
            $t->uuid('author_policy_id')->nullable();
            $t->foreign(['author_policy_id', 'project_id'], 'pms_entry_author_policy_fk')
                ->references(['id', 'project_id'])->on('pms_author_policies')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_pms_author_policy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_version integer; person record;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('pms-entry:' || NEW.project_id::text, 0));
    SELECT COALESCE(max(version),0) INTO current_version FROM pms_author_policies WHERE project_id=NEW.project_id;
    IF NEW.version<>current_version+1 THEN
        RAISE EXCEPTION 'PMS_AUTHOR_POLICY_STALE' USING ERRCODE='23514';
    END IF;
    SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
        WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
        AND EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
    IF NOT FOUND THEN RAISE EXCEPTION 'PMS_AUTHOR_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
    NEW.actor_name:=person.name;
    NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $$;
CREATE TRIGGER pms_author_policy_guard BEFORE INSERT ON pms_author_policies
FOR EACH ROW EXECUTE FUNCTION epcc_guard_pms_author_policy();

CREATE OR REPLACE FUNCTION epcc_pms_author_policy(pid uuid, aid uuid, cid uuid)
RETURNS uuid LANGUAGE sql STABLE AS $$
    WITH latest AS (SELECT id,selectors FROM pms_author_policies WHERE project_id=pid ORDER BY version DESC LIMIT 1)
    SELECT latest.id FROM latest, users u JOIN project_memberships pm ON pm.user_id=u.id
    WHERE u.id=aid AND u.company_id=cid AND u.is_active AND pm.project_id=pid
        AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid
        AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid
        AND epcc_project_is_writable(pid)
        AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
        AND (pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope,'PIPING'))
        AND EXISTS (SELECT 1 FROM jsonb_array_elements(latest.selectors) selection
            WHERE selection->>'selector'='user:' || u.id::text
            OR EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
                AND selection->>'selector'='role:' || cid::text || ':' || r.code))
    LIMIT 1
$$;

CREATE OR REPLACE FUNCTION epcc_guard_pms_author_event() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE policy_id uuid;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('pms-entry:' || NEW.project_id::text,0));
    policy_id:=epcc_pms_author_policy(NEW.project_id,NEW.created_by,NEW.company_id);
    IF policy_id IS NULL THEN RAISE EXCEPTION 'PMS_AUTHOR_NOT_NOMINATED' USING ERRCODE='42501'; END IF;
    IF NEW.author_policy_id IS NOT NULL AND NEW.author_policy_id<>policy_id THEN
        RAISE EXCEPTION 'PMS_AUTHOR_POLICY_STALE' USING ERRCODE='23514';
    END IF;
    NEW.author_policy_id:=policy_id;
    RETURN NEW;
END $$;
CREATE TRIGGER pms_author_event_guard BEFORE INSERT ON pms_entry_events
FOR EACH ROW EXECUTE FUNCTION epcc_guard_pms_author_event();
SQL);
        // ADR-013 §11: preserve reads, close the separately documented notes gap.
        $noteScope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid";
        $noteOwned = $noteScope." AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid";
        DB::statement("ALTER POLICY piping_class_notes_read ON piping_class_notes USING ({$noteScope})");
        DB::statement("ALTER POLICY piping_class_notes_write ON piping_class_notes USING ({$noteOwned}) WITH CHECK ({$noteOwned})");
        DB::statement("CREATE POLICY pms_notes_participant ON piping_class_notes AS RESTRICTIVE FOR ALL USING
            (EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=piping_class_notes.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))");
        DB::statement('CREATE POLICY pms_notes_archive_write ON piping_class_notes AS RESTRICTIVE FOR ALL USING (true) WITH CHECK (epcc_project_is_writable(project_id))');
        DB::statement('CREATE POLICY pms_notes_archive_delete ON piping_class_notes AS RESTRICTIVE FOR DELETE USING (epcc_project_is_writable(project_id))');
    }

    public function down(): void
    {
        throw new RuntimeException('PMS author-policy history is forward-only; do not erase authorizing evidence.');
    }
};
