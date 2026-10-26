<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Non-approving opinions bound to both immutable narrative and structure identities. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE scope_structure_declarations ADD CONSTRAINT scope_structure_tenant_key UNIQUE(project_id,company_id,id)');
        Schema::create('scope_structure_reviews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->uuid('declaration_id');
            $t->uuid('proposal_id');
            $t->foreignUuid('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('declaration_version');
            $t->unsignedInteger('proposal_version');
            $t->char('declaration_digest', 64);
            $t->char('proposal_digest', 64);
            $t->string('outcome', 32);
            $t->string('reason', 4000);
            $t->foreignUuid('nomination_policy_id')->constrained('scope_admission_actor_policies')->restrictOnDelete();
            $t->foreignUuid('reader_policy_id')->constrained('demand_reader_policies')->restrictOnDelete();
            $t->jsonb('envelope');
            $t->char('digest', 64);
            $t->string('state', 32)->default('UNVERIFIED_OPINION');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['declaration_id', 'reviewer_id'], 'scope_structure_review_once');
            $t->foreign(['project_id', 'company_id', 'declaration_id'], 'scope_bound_review_declaration_tenant')->references(['project_id', 'company_id', 'id'])->on('scope_structure_declarations')->restrictOnDelete();
            $t->foreign(['project_id', 'company_id', 'proposal_id'], 'scope_bound_review_proposal_tenant')->references(['project_id', 'company_id', 'id'])->on('scope_proposal_envelopes')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE scope_structure_reviews ADD CHECK(state='UNVERIFIED_OPINION' AND outcome IN ('COMMENTED','CHANGES_REQUESTED')
 AND proposal_version>0 AND declaration_version>0 AND length(btrim(reason))>0 AND reason ~ '[^[:space:]]' AND jsonb_typeof(envelope)='object'
 AND digest ~ '^[a-f0-9]{64}$' AND proposal_digest ~ '^[a-f0-9]{64}$' AND declaration_digest ~ '^[a-f0-9]{64}$');
ALTER TABLE scope_structure_reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE scope_structure_reviews FORCE ROW LEVEL SECURITY;
CREATE POLICY scope_bound_opinion_select ON scope_structure_reviews FOR SELECT USING(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE POLICY scope_bound_opinion_insert ON scope_structure_reviews FOR INSERT WITH CHECK(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE TRIGGER scope_bound_opinion_immutable BEFORE UPDATE OR DELETE ON scope_structure_reviews FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();

CREATE OR REPLACE FUNCTION epcc_scope_structure_review_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
DECLARE p projects%ROWTYPE; d scope_structure_declarations%ROWTYPE; e scope_proposal_envelopes%ROWTYPE; b jsonb; claim jsonb;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.state<>'ACTIVE' OR p.archived_at IS NOT NULL
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'SCOPE_BOUND_REVIEW_CONTEXT' USING ERRCODE='42501'; END IF;
 SELECT * INTO d FROM scope_structure_declarations WHERE id=NEW.declaration_id AND project_id=NEW.project_id AND company_id=NEW.company_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'SCOPE_BOUND_REVIEW_DECLARATION' USING ERRCODE='42501'; END IF;
 SELECT * INTO e FROM scope_proposal_envelopes WHERE id=d.proposal_id AND project_id=d.project_id AND company_id=d.company_id;
 IF NOT FOUND OR e.id IS DISTINCT FROM NEW.proposal_id OR e.proposer_id=NEW.reviewer_id OR d.declared_by=NEW.reviewer_id
 OR d.declared_by<>e.proposer_id OR d.state<>'UNVERIFIED_STRUCTURE' OR e.state<>'UNVERIFIED_PROPOSAL'
 OR NEW.proposal_version<>e.version OR NEW.declaration_version<>d.version OR d.version<>e.version
 OR NEW.proposal_digest<>e.digest OR NEW.declaration_digest<>d.digest OR d.proposal_digest<>e.digest
 OR e.digest<>encode(sha256(convert_to(e.envelope::text,'UTF8')),'hex')
 OR d.digest<>encode(sha256(convert_to(d.envelope::text,'UTF8')),'hex')
 OR d.envelope->'nodes' IS DISTINCT FROM d.nodes OR d.envelope->'coverage' IS DISTINCT FROM d.coverage
 OR d.envelope->'source_binding' IS DISTINCT FROM e.binding THEN
 RAISE EXCEPTION 'SCOPE_BOUND_REVIEW_EXACT_INDEPENDENT_CONTENT' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM scope_proposal_envelopes n WHERE n.project_id=e.project_id AND n.company_id=e.company_id AND n.proposer_id=e.proposer_id
 AND n.batch_id=e.batch_id AND n.scope_id=e.scope_id AND n.hierarchy_revision_id=e.hierarchy_revision_id AND n.version>e.version) THEN
 RAISE EXCEPTION 'SCOPE_BOUND_REVIEW_SUPERSEDED' USING ERRCODE='23514'; END IF;
 NEW.nomination_policy_id:=epcc_scope_dossier_nomination(e.project_id,e.company_id,NEW.reviewer_id,e.document_id,e.scope_id,e.hierarchy_revision_id,'review');
 NEW.reader_policy_id:=epcc_scope_dossier_nomination(e.project_id,e.company_id,NEW.reviewer_id,e.document_id,e.scope_id,e.hierarchy_revision_id,'read');
 b:=epcc_scope_dossier_binding(e.project_id,e.company_id,NEW.reviewer_id,e.batch_id,e.document_id,e.scope_id,e.hierarchy_revision_id);
 IF NEW.nomination_policy_id IS NULL OR NEW.reader_policy_id IS NULL OR b IS NULL OR b IS DISTINCT FROM e.binding THEN
 RAISE EXCEPTION 'SCOPE_BOUND_REVIEW_SOURCE_OR_AUTHORITY' USING ERRCODE='42501'; END IF;
 claim:=epcc_scope_structure_claim(e.project_id,e.batch_id,e.scope_id,e.hierarchy_revision_id,e.observation_id,d.nodes,d.coverage);
 IF claim IS NULL OR claim->'nodes' IS DISTINCT FROM d.nodes OR claim->'coverage' IS DISTINCT FROM d.coverage THEN
 RAISE EXCEPTION 'SCOPE_BOUND_REVIEW_DEPENDENCY_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 NEW.created_at:=clock_timestamp();
 NEW.envelope:=jsonb_build_object('schema_version',1,'kind','UNVERIFIED_STRUCTURE_OPINION','id',NEW.id,
 'project_id',NEW.project_id,'company_id',NEW.company_id,'reviewer_id',NEW.reviewer_id,
 'declaration_id',d.id,'declaration_version',d.version,'declaration_digest',d.digest,
 'proposal_id',e.id,'proposal_version',e.version,'proposal_digest',e.digest,'source_binding',b,
 'outcome',NEW.outcome,'reason',NEW.reason,'nomination_policy_id',NEW.nomination_policy_id,'reader_policy_id',NEW.reader_policy_id);
 NEW.digest:=encode(sha256(convert_to(NEW.envelope::text,'UTF8')),'hex'); RETURN NEW;
END $$;
CREATE TRIGGER scope_bound_opinion_insert_guard BEFORE INSERT ON scope_structure_reviews FOR EACH ROW EXECUTE FUNCTION epcc_scope_structure_review_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_structure_reviews');
        DB::unprepared('DROP FUNCTION epcc_scope_structure_review_guard(); ALTER TABLE scope_structure_declarations DROP CONSTRAINT scope_structure_tenant_key;');
    }
};
