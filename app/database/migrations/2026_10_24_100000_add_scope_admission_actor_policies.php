<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_admission_actor_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('target_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('document_id');
            $t->uuid('scope_id');
            $t->string('hierarchy_revision_id', 96);
            $t->string('action', 16);
            $t->unsignedInteger('version');
            $t->boolean('suspended');
            $t->jsonb('selectors');
            $t->string('reason', 2000);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['project_id', 'target_company_id', 'document_id', 'scope_id', 'hierarchy_revision_id', 'action', 'version'], 'scope_admission_actor_version_key');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE scope_admission_actor_policies ADD FOREIGN KEY(project_id,document_id) REFERENCES mto_documents(project_id,id) ON DELETE RESTRICT;
ALTER TABLE scope_admission_actor_policies ADD FOREIGN KEY(project_id,scope_id) REFERENCES subsystems(project_id,id) ON DELETE RESTRICT;
ALTER TABLE scope_admission_actor_policies ADD CHECK(action IN ('propose','review','record') AND version>0 AND length(btrim(reason))>0 AND length(btrim(hierarchy_revision_id))>0
 AND jsonb_typeof(selectors)='array' AND ((suspended AND selectors='[]'::jsonb) OR (NOT suspended AND jsonb_array_length(selectors)>0)));
ALTER TABLE scope_admission_actor_policies ENABLE ROW LEVEL SECURITY;
ALTER TABLE scope_admission_actor_policies FORCE ROW LEVEL SECURITY;
CREATE POLICY scope_admission_actor_metadata ON scope_admission_actor_policies FOR SELECT USING(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND
 (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid OR target_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid));
CREATE POLICY scope_admission_actor_insert ON scope_admission_actor_policies FOR INSERT WITH CHECK(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM projects p WHERE p.id=project_id AND p.owner_company_id=company_id));
CREATE OR REPLACE FUNCTION epcc_scope_admission_actor_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p projects%ROWTYPE; last_version integer;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.archived_at IS NOT NULL OR p.state<>'ACTIVE' OR p.owner_company_id<>NEW.company_id
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'SCOPE_ADMISSION_ACTOR_CONFIG_SCOPE' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.created_by AND company_id=NEW.company_id AND is_active) THEN
 RAISE EXCEPTION 'SCOPE_ADMISSION_ACTOR_ACTOR_SCOPE' USING ERRCODE='42501'; END IF;
 SELECT COALESCE(max(version),0) INTO last_version FROM scope_admission_actor_policies
 WHERE project_id=NEW.project_id AND target_company_id=NEW.target_company_id AND document_id=NEW.document_id
 AND scope_id=NEW.scope_id AND hierarchy_revision_id=NEW.hierarchy_revision_id AND action=NEW.action;
 IF NEW.version<>last_version+1 THEN RAISE EXCEPTION 'SCOPE_ADMISSION_ACTOR_STALE_VERSION' USING ERRCODE='23514'; END IF;
 IF NEW.suspended AND NEW.selectors='[]'::jsonb AND last_version>0 AND EXISTS(
 SELECT 1 FROM scope_admission_actor_policies h WHERE h.project_id=NEW.project_id AND h.company_id=NEW.company_id
 AND h.target_company_id=NEW.target_company_id AND h.document_id=NEW.document_id AND h.scope_id=NEW.scope_id
 AND h.hierarchy_revision_id=NEW.hierarchy_revision_id AND h.action=NEW.action AND h.version=last_version) THEN
 RETURN NEW; -- Reduction of this exact recorded key only; no source reads or new selectors.
 END IF;
 IF NOT EXISTS(SELECT 1 FROM project_company WHERE project_id=NEW.project_id AND company_id=NEW.target_company_id)

 OR NOT EXISTS(SELECT 1 FROM mto_documents WHERE id=NEW.document_id AND project_id=NEW.project_id AND discipline='PIPING')
 OR NOT EXISTS(SELECT 1 FROM accepted_mto_rows r JOIN accepted_mto_batches b ON b.id=r.accepted_mto_batch_id AND b.project_id=r.project_id AND b.mto_document_id=r.mto_document_id AND b.mto_revision_id=r.mto_revision_id
 WHERE r.project_id=NEW.project_id AND r.mto_document_id=NEW.document_id AND r.scope_id=NEW.scope_id
 AND r.hierarchy_revision_id=NEW.hierarchy_revision_id AND b.hierarchy_revision_id=NEW.hierarchy_revision_id AND b.state='ACCEPTED') THEN
 RAISE EXCEPTION 'SCOPE_ADMISSION_ACTOR_SOURCE_SCOPE' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER scope_admission_actor_insert_guard BEFORE INSERT ON scope_admission_actor_policies FOR EACH ROW EXECUTE FUNCTION epcc_scope_admission_actor_insert_guard();
CREATE TRIGGER scope_admission_actor_immutable BEFORE UPDATE OR DELETE ON scope_admission_actor_policies FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_admission_actor_policies');
        DB::statement('DROP FUNCTION IF EXISTS epcc_scope_admission_actor_insert_guard()');
    }
};
