<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Source-bound unverified narrative envelopes, NOT technical packages or admission events. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_proposal_envelopes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('proposer_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('batch_id')->constrained('accepted_mto_batches')->restrictOnDelete();
            $t->uuid('document_id');
            $t->uuid('scope_id');
            $t->string('hierarchy_revision_id', 96);
            $t->unsignedInteger('version');
            $t->uuid('previous_id')->nullable();
            $t->string('statement', 4000);
            $t->foreignUuid('nomination_policy_id')->constrained('scope_admission_actor_policies')->restrictOnDelete();
            $t->foreignUuid('reader_policy_id')->constrained('demand_reader_policies')->restrictOnDelete();
            $t->foreignUuid('observation_id')->constrained('mto_scope_observations')->restrictOnDelete();
            $t->jsonb('binding');
            $t->jsonb('envelope');
            $t->char('digest', 64);
            $t->string('state', 32)->default('UNVERIFIED_PROPOSAL');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['project_id', 'company_id', 'id'], 'scope_envelope_tenant_key');
            $t->unique(['project_id', 'company_id', 'proposer_id', 'batch_id', 'scope_id', 'hierarchy_revision_id', 'version'], 'scope_envelope_stream_version');
        });
        Schema::create('scope_proposal_reviews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->uuid('proposal_id');
            $t->foreignUuid('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('proposal_version');
            $t->char('proposal_digest', 64);
            $t->string('outcome', 32);
            $t->string('reason', 4000);
            $t->foreignUuid('nomination_policy_id')->constrained('scope_admission_actor_policies')->restrictOnDelete();
            $t->foreignUuid('reader_policy_id')->constrained('demand_reader_policies')->restrictOnDelete();
            $t->jsonb('envelope');
            $t->char('digest', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['project_id', 'company_id', 'proposal_id'], 'scope_review_exact_tenant')->references(['project_id', 'company_id', 'id'])->on('scope_proposal_envelopes')->restrictOnDelete();
            $t->unique(['proposal_id', 'reviewer_id'], 'scope_review_once_per_version');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE scope_proposal_envelopes ADD FOREIGN KEY(previous_id) REFERENCES scope_proposal_envelopes(id) ON DELETE RESTRICT;
ALTER TABLE scope_proposal_envelopes ADD FOREIGN KEY(project_id,document_id) REFERENCES mto_documents(project_id,id) ON DELETE RESTRICT;
ALTER TABLE scope_proposal_envelopes ADD FOREIGN KEY(project_id,scope_id) REFERENCES subsystems(project_id,id) ON DELETE RESTRICT;
ALTER TABLE scope_proposal_envelopes ADD CHECK(version>0 AND state='UNVERIFIED_PROPOSAL' AND length(btrim(statement))>0
 AND digest ~ '^[a-f0-9]{64}$' AND jsonb_typeof(binding)='object' AND jsonb_typeof(envelope)='object');
ALTER TABLE scope_proposal_reviews ADD CHECK(proposal_version>0 AND outcome IN ('COMMENTED','CHANGES_REQUESTED')
 AND length(btrim(reason))>0 AND digest ~ '^[a-f0-9]{64}$' AND proposal_digest ~ '^[a-f0-9]{64}$' AND jsonb_typeof(envelope)='object');

CREATE OR REPLACE FUNCTION epcc_scope_dossier_nomination(pid uuid,cid uuid,aid uuid,did uuid,sid uuid,pin text,act text)
RETURNS uuid LANGUAGE sql STABLE SET search_path=public,pg_catalog AS $$
 WITH h AS (
 (SELECT id,suspended,selectors FROM demand_reader_policies
  WHERE act='read' AND project_id=pid AND target_company_id=cid AND document_id=did AND scope_id=sid AND hierarchy_revision_id=pin ORDER BY version DESC LIMIT 1)
 UNION ALL
 (SELECT id,suspended,selectors FROM scope_admission_actor_policies
  WHERE act IN ('propose','review','record') AND action=act AND project_id=pid AND target_company_id=cid AND document_id=did AND scope_id=sid AND hierarchy_revision_id=pin ORDER BY version DESC LIMIT 1)
 ) SELECT h.id FROM h
 JOIN users u ON u.id=aid AND u.company_id=cid AND u.is_active
 JOIN project_memberships pm ON pm.user_id=u.id AND pm.project_id=pid
 JOIN companies c ON c.id=cid JOIN plans pl ON pl.id=c.plan_id JOIN projects p ON p.id=pid
 WHERE NOT h.suspended AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
 AND (pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope,'PIPING'))
 AND (jsonb_exists(pl.modules::jsonb,'proc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,p.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'))
 AND (jsonb_exists(h.selectors,'user:' || aid::text) OR EXISTS(
 SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code) AND jsonb_exists(h.selectors,'role:' || cid::text || ':' || r.code)))
$$;

-- Metadata-only source binding, with full row-set visibility/integrity check.
-- Single-scope sources only in this first increment. No today's-graph history reconstruction.
CREATE OR REPLACE FUNCTION epcc_scope_dossier_binding(pid uuid,cid uuid,aid uuid,bid uuid,did uuid,sid uuid,pin text)
RETURNS jsonb LANGUAGE sql STABLE SET search_path=public,pg_catalog AS $$
 SELECT jsonb_build_object('schema_version',1,'project_id',b.project_id,'source_company_id',b.company_id,
 'batch_id',b.id,'document_id',b.mto_document_id,'revision_id',b.mto_revision_id,'scope_id',sid,
 'observation_id',o.id,'payload_hash',o.payload_hash,'source_rows_hash',o.source_rows_hash,
 'hierarchy_revision_id',b.hierarchy_revision_id,'header_scope_id',b.header_scope_id)
 FROM accepted_mto_batches b JOIN mto_scope_observations o
 ON o.accepted_mto_batch_id=b.id AND o.project_id=b.project_id AND o.company_id=b.company_id
 AND o.mto_document_id=b.mto_document_id AND o.mto_revision_id=b.mto_revision_id
 JOIN mto_documents m ON m.id=b.mto_document_id AND m.project_id=b.project_id AND m.discipline='PIPING'
 WHERE b.id=bid AND b.project_id=pid AND b.mto_document_id=did AND b.state='ACCEPTED'
 AND length(btrim(pin))>0 AND b.hierarchy_revision_id=pin AND (b.header_scope_id IS NULL OR b.header_scope_id=sid)
 AND epcc_scope_dossier_nomination(pid,cid,aid,did,sid,pin,'read') IS NOT NULL
 AND o.origin='DATABASE_OBSERVATION' AND o.admission_state='UNADMITTED'
 AND o.payload->'schema_version'='1'::jsonb AND o.payload->>'capture_kind'='DATABASE_OBSERVATION'
 AND o.payload->>'project_id'=pid::text AND o.payload->>'batch_id'=bid::text
 AND o.payload->>'claimed_hierarchy_revision_id'=pin
 AND o.payload->'header_scope_id'=to_jsonb(b)->'header_scope_id'
 AND o.payload_hash=encode(sha256(convert_to(o.payload::text,'UTF8')),'hex')
 AND o.source_rows_hash=encode(sha256(convert_to(epcc_mto_observed_source_rows(b.id,b.project_id)::text,'UTF8')),'hex')
 AND jsonb_typeof(o.payload->'rows')='array' AND jsonb_array_length(o.payload->'rows')>0
 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(o.payload->'rows') r
 WHERE r->>'scope_id' IS DISTINCT FROM sid::text OR r->>'hierarchy_revision_id' IS DISTINCT FROM pin)
$$;

CREATE OR REPLACE FUNCTION epcc_scope_proposal_envelope_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
DECLARE p projects%ROWTYPE; h scope_proposal_envelopes%ROWTYPE;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.state<>'ACTIVE' OR p.archived_at IS NOT NULL
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'SCOPE_ENVELOPE_CONTEXT' USING ERRCODE='42501'; END IF;
 NEW.nomination_policy_id:=epcc_scope_dossier_nomination(NEW.project_id,NEW.company_id,NEW.proposer_id,NEW.document_id,NEW.scope_id,NEW.hierarchy_revision_id,'propose');
 NEW.reader_policy_id:=epcc_scope_dossier_nomination(NEW.project_id,NEW.company_id,NEW.proposer_id,NEW.document_id,NEW.scope_id,NEW.hierarchy_revision_id,'read');
 NEW.binding:=epcc_scope_dossier_binding(NEW.project_id,NEW.company_id,NEW.proposer_id,NEW.batch_id,NEW.document_id,NEW.scope_id,NEW.hierarchy_revision_id);
 IF NEW.nomination_policy_id IS NULL OR NEW.reader_policy_id IS NULL OR NEW.binding IS NULL THEN
 RAISE EXCEPTION 'SCOPE_ENVELOPE_SOURCE_OR_AUTHORITY' USING ERRCODE='42501'; END IF;
 SELECT * INTO h FROM scope_proposal_envelopes WHERE project_id=NEW.project_id AND company_id=NEW.company_id AND proposer_id=NEW.proposer_id
 AND batch_id=NEW.batch_id AND scope_id=NEW.scope_id AND hierarchy_revision_id=NEW.hierarchy_revision_id ORDER BY version DESC LIMIT 1;
 IF NEW.version<>COALESCE(h.version,0)+1 OR NEW.previous_id IS DISTINCT FROM h.id THEN
 RAISE EXCEPTION 'SCOPE_ENVELOPE_STALE' USING ERRCODE='23514'; END IF;
 NEW.observation_id:=(NEW.binding->>'observation_id')::uuid;
 NEW.created_at:=clock_timestamp();
 NEW.envelope:=jsonb_build_object('schema_version',1,'kind','UNVERIFIED_NARRATIVE_PROPOSAL','id',NEW.id,
 'project_id',NEW.project_id,'company_id',NEW.company_id,'proposer_id',NEW.proposer_id,'version',NEW.version,
 'previous_id',NEW.previous_id,'statement',NEW.statement,'source_binding',NEW.binding,
 'nomination_policy_id',NEW.nomination_policy_id,'reader_policy_id',NEW.reader_policy_id);
 NEW.digest:=encode(sha256(convert_to(NEW.envelope::text,'UTF8')),'hex');
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION epcc_scope_proposal_review_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
DECLARE p projects%ROWTYPE; e scope_proposal_envelopes%ROWTYPE; binding jsonb;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.state<>'ACTIVE' OR p.archived_at IS NOT NULL
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'SCOPE_REVIEW_CONTEXT' USING ERRCODE='42501'; END IF;
 SELECT * INTO e FROM scope_proposal_envelopes WHERE id=NEW.proposal_id AND project_id=NEW.project_id AND company_id=NEW.company_id;
 IF NOT FOUND OR e.proposer_id=NEW.reviewer_id OR NEW.proposal_version<>e.version OR NEW.proposal_digest<>e.digest THEN
 RAISE EXCEPTION 'SCOPE_REVIEW_EXACT_INDEPENDENT_VERSION' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM scope_proposal_envelopes n WHERE n.project_id=e.project_id AND n.company_id=e.company_id
 AND n.proposer_id=e.proposer_id AND n.batch_id=e.batch_id AND n.scope_id=e.scope_id AND n.hierarchy_revision_id=e.hierarchy_revision_id AND n.version>e.version) THEN
 RAISE EXCEPTION 'SCOPE_REVIEW_SUPERSEDED' USING ERRCODE='23514'; END IF;
 NEW.nomination_policy_id:=epcc_scope_dossier_nomination(e.project_id,NEW.company_id,NEW.reviewer_id,e.document_id,e.scope_id,e.hierarchy_revision_id,'review');
 NEW.reader_policy_id:=epcc_scope_dossier_nomination(e.project_id,NEW.company_id,NEW.reviewer_id,e.document_id,e.scope_id,e.hierarchy_revision_id,'read');
 binding:=epcc_scope_dossier_binding(e.project_id,NEW.company_id,NEW.reviewer_id,e.batch_id,e.document_id,e.scope_id,e.hierarchy_revision_id);
 IF NEW.nomination_policy_id IS NULL OR NEW.reader_policy_id IS NULL OR binding IS NULL OR binding IS DISTINCT FROM e.binding
 OR e.digest<>encode(sha256(convert_to(e.envelope::text,'UTF8')),'hex') THEN
 RAISE EXCEPTION 'SCOPE_REVIEW_SOURCE_OR_AUTHORITY' USING ERRCODE='42501'; END IF;
 NEW.created_at:=clock_timestamp();
 NEW.envelope:=jsonb_build_object('schema_version',1,'kind','UNVERIFIED_NARRATIVE_REVIEW','id',NEW.id,
 'project_id',NEW.project_id,'company_id',NEW.company_id,'proposal_id',e.id,'proposal_version',e.version,'proposal_digest',e.digest,
 'reviewer_id',NEW.reviewer_id,'outcome',NEW.outcome,'reason',NEW.reason,
 'nomination_policy_id',NEW.nomination_policy_id,'reader_policy_id',NEW.reader_policy_id);
 NEW.digest:=encode(sha256(convert_to(NEW.envelope::text,'UTF8')),'hex');
 RETURN NEW;
END $$;
SQL);
        foreach (['scope_proposal_envelopes', 'scope_proposal_reviews'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY; ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
 CREATE POLICY dossier_select ON {$table} FOR SELECT USING(project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
 CREATE POLICY dossier_insert ON {$table} FOR INSERT WITH CHECK(project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
 CREATE TRIGGER dossier_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();");
        }
        DB::unprepared('CREATE TRIGGER dossier_insert_guard BEFORE INSERT ON scope_proposal_envelopes FOR EACH ROW EXECUTE FUNCTION epcc_scope_proposal_envelope_guard();
CREATE TRIGGER dossier_insert_guard BEFORE INSERT ON scope_proposal_reviews FOR EACH ROW EXECUTE FUNCTION epcc_scope_proposal_review_guard();');
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_proposal_reviews');
        Schema::dropIfExists('scope_proposal_envelopes');
        DB::unprepared('DROP FUNCTION epcc_scope_proposal_review_guard(); DROP FUNCTION epcc_scope_proposal_envelope_guard();
DROP FUNCTION epcc_scope_dossier_binding(uuid,uuid,uuid,uuid,uuid,uuid,text); DROP FUNCTION epcc_scope_dossier_nomination(uuid,uuid,uuid,uuid,uuid,text,text);');
    }
};
