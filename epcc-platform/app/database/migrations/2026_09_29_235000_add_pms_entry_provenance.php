<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Older policies cast an empty restored GUC directly to uuid. Make the populated upgrade fail closed, not fail with 22P02.
        DB::statement("ALTER POLICY piping_class_items_read ON piping_class_items USING (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid)");
        DB::statement("ALTER POLICY piping_class_items_write ON piping_class_items USING (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid) WITH CHECK (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)");
        Schema::table('piping_classes',function(Blueprint $t){
            $t->integer('entry_version')->default(0);$t->jsonb('entry_provenance')->nullable();
            $t->string('source_document',120)->nullable();$t->string('source_revision',60)->nullable();$t->text('source_text')->nullable();
            $t->unique(['id','project_id'],'pms_class_project_unique');
        });
        Schema::table('project_fitting_definitions',fn(Blueprint $t)=>$t->unique(['id','project_id'],'pms_definition_project_unique'));
        Schema::table('piping_class_items',function(Blueprint $t){
            $t->integer('entry_version')->default(0);$t->uuid('project_definition_id')->nullable();
            $t->jsonb('entry_provenance')->nullable();
            $t->unique(['id','piping_class_id','project_id'],'pms_item_class_project_unique');
            $t->foreign(['project_definition_id','project_id'],'pms_item_definition_fk')->references(['id','project_id'])->on('project_fitting_definitions')->restrictOnDelete();
        });
        Schema::create('pms_entry_events',function(Blueprint $t){
            $t->uuid('id')->primary();$t->uuid('project_id');$t->uuid('company_id');$t->uuid('piping_class_id');$t->uuid('item_id')->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();$t->string('actor_name');$t->timestampTz('created_at');
            $t->integer('version');$t->text('reason');$t->jsonb('before_data')->nullable();$t->jsonb('after_data');
            $t->foreign(['piping_class_id','project_id'],'pms_event_class_fk')->references(['id','project_id'])->on('piping_classes')->restrictOnDelete();
            $t->foreign(['item_id','piping_class_id','project_id'],'pms_event_item_fk')->references(['id','piping_class_id','project_id'])->on('piping_class_items')->restrictOnDelete();
            $t->index(['project_id','piping_class_id','created_at']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE pms_entry_events ADD CONSTRAINT pms_event_version_unique UNIQUE NULLS NOT DISTINCT (piping_class_id,item_id,version);
            ALTER TABLE pms_entry_events ADD CONSTRAINT pms_event_shape CHECK (version>0 AND length(trim(reason))>0 AND jsonb_typeof(after_data)='object');
            ALTER TABLE pms_entry_events ENABLE ROW LEVEL SECURITY;
            ALTER TABLE pms_entry_events FORCE ROW LEVEL SECURITY;
            CREATE OR REPLACE FUNCTION epcc_guard_pms_definition_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE step_count integer; approval_count integer; has_return boolean;
            BEGIN
                IF NEW.project_definition_id IS NULL THEN RETURN NEW; END IF;
                IF TG_OP='UPDATE' THEN
                    IF NEW.project_definition_id IS NOT DISTINCT FROM OLD.project_definition_id AND NEW.project_id=OLD.project_id THEN RETURN NEW; END IF;
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('fitting-review:'||NEW.project_id::text,0));
                SELECT jsonb_array_length(p.steps),count(d.id),COALESCE(bool_or(d.decision<>'APPROVE'),false)
                    INTO step_count,approval_count,has_return
                    FROM fitting_review_runs r JOIN fitting_review_policies p ON p.id=r.policy_id
                    LEFT JOIN fitting_review_decisions d ON d.run_id=r.id
                    WHERE r.definition_id=NEW.project_definition_id AND r.project_id=NEW.project_id GROUP BY p.steps;
                IF step_count IS NULL OR step_count<1 OR approval_count<>step_count OR has_return
                    OR EXISTS(SELECT 1 FROM project_fitting_definitions WHERE revises_id=NEW.project_definition_id)
                THEN RAISE EXCEPTION 'PMS requires current project-approved definition' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER pms_definition_link_guard BEFORE INSERT OR UPDATE ON piping_class_items
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_pms_definition_link();
        SQL);
        $scope="project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=pms_entry_events.project_id AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
        DB::statement("CREATE POLICY pms_event_read ON pms_entry_events FOR SELECT USING ({$scope})");
        DB::statement("CREATE POLICY pms_event_insert ON pms_entry_events FOR INSERT WITH CHECK ({$scope} AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND epcc_project_is_writable(project_id)
            AND EXISTS(SELECT 1 FROM users u JOIN project_memberships m ON m.user_id=u.id WHERE u.id=pms_entry_events.created_by AND u.company_id=pms_entry_events.company_id AND u.is_active AND m.project_id=pms_entry_events.project_id AND (m.discipline_scope IS NULL OR jsonb_exists(m.discipline_scope,'PIPING'))))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_guard_pms_event() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE company uuid; current_version integer; name text;
            BEGIN
                SELECT company_id,entry_version INTO company,current_version FROM piping_classes WHERE id=NEW.piping_class_id AND project_id=NEW.project_id;
                IF NOT FOUND OR company<>NEW.company_id THEN RAISE EXCEPTION 'PMS class company mismatch' USING ERRCODE='42501'; END IF;
                IF NEW.item_id IS NOT NULL THEN
                    SELECT company_id,entry_version INTO company,current_version FROM piping_class_items WHERE id=NEW.item_id AND project_id=NEW.project_id AND piping_class_id=NEW.piping_class_id;
                    IF NOT FOUND OR company<>NEW.company_id THEN RAISE EXCEPTION 'PMS item scope mismatch' USING ERRCODE='42501'; END IF;
                END IF;
                IF NEW.version<>current_version THEN RAISE EXCEPTION 'PMS history version mismatch' USING ERRCODE='23514'; END IF;
                SELECT u.name INTO name FROM users u WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active;
                IF NOT FOUND THEN RAISE EXCEPTION 'PMS inactive author' USING ERRCODE='42501'; END IF;
                NEW.actor_name:=name;NEW.created_at:=CURRENT_TIMESTAMP;RETURN NEW;
            END; $$;
            CREATE TRIGGER pms_event_guard BEFORE INSERT ON pms_entry_events FOR EACH ROW EXECUTE FUNCTION epcc_guard_pms_event();
        SQL);
        DB::statement("CREATE POLICY pms_item_participant ON piping_class_items AS RESTRICTIVE FOR ALL USING (EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=piping_class_items.project_id AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))");
        // The original item table predates the project archive freeze.
        DB::statement('CREATE POLICY pms_item_archive_write ON piping_class_items AS RESTRICTIVE FOR ALL USING (true) WITH CHECK (epcc_project_is_writable(project_id))');
        DB::statement('CREATE POLICY pms_item_archive_delete ON piping_class_items AS RESTRICTIVE FOR DELETE USING (epcc_project_is_writable(project_id))');
    }
    public function down(): void { throw new RuntimeException('PMS entry history is forward-only. Restore an explicitly selected backup.'); }
};
