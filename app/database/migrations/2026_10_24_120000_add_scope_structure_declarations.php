<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Unverified structure claims, never admission. No external evidence references in this slice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_structure_declarations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->uuid('proposal_id')->unique();
            $t->foreignUuid('declared_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->uuid('previous_id')->nullable();
            $t->char('proposal_digest', 64);
            $t->jsonb('nodes');
            $t->jsonb('coverage');
            $t->foreignUuid('nomination_policy_id')->constrained('scope_admission_actor_policies')->restrictOnDelete();
            $t->foreignUuid('reader_policy_id')->constrained('demand_reader_policies')->restrictOnDelete();
            $t->jsonb('envelope');
            $t->char('digest', 64);
            $t->string('state', 32)->default('UNVERIFIED_STRUCTURE');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['project_id', 'company_id', 'proposal_id'], 'scope_structure_exact_tenant')->references(['project_id', 'company_id', 'id'])->on('scope_proposal_envelopes')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE scope_structure_declarations ADD FOREIGN KEY(previous_id) REFERENCES scope_structure_declarations(id) ON DELETE RESTRICT;
ALTER TABLE scope_structure_declarations ADD CHECK(version>0 AND state='UNVERIFIED_STRUCTURE' AND proposal_digest ~ '^[a-f0-9]{64}$'
 AND digest ~ '^[a-f0-9]{64}$' AND jsonb_typeof(nodes)='array' AND jsonb_typeof(coverage)='array' AND jsonb_typeof(envelope)='object');
ALTER TABLE scope_structure_declarations ENABLE ROW LEVEL SECURITY;
ALTER TABLE scope_structure_declarations FORCE ROW LEVEL SECURITY;
CREATE POLICY scope_structure_select ON scope_structure_declarations FOR SELECT USING(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE POLICY scope_structure_insert ON scope_structure_declarations FOR INSERT WITH CHECK(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE TRIGGER scope_structure_immutable BEFORE UPDATE OR DELETE ON scope_structure_declarations FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();

-- Returns a canonical structure claim only. NULL means malformed, incomplete or not independently visible.
CREATE OR REPLACE FUNCTION epcc_scope_structure_claim(pid uuid,bid uuid,sid uuid,pin text,oid uuid,ns jsonb,cv jsonb)
RETURNS jsonb LANGUAGE plpgsql STABLE SET search_path=public,pg_catalog AS $$
DECLARE captured jsonb; n jsonb; c jsonb; canonical_nodes jsonb; canonical_rows jsonb; actual_rows jsonb;
 allowed jsonb; keys text[]; ids text[]; cursor_id text; parent_node jsonb; start_node jsonb; seen text[]; node_count integer;
BEGIN
 IF jsonb_typeof(ns) IS DISTINCT FROM 'array' OR jsonb_typeof(cv) IS DISTINCT FROM 'array' THEN RETURN NULL; END IF;
 IF jsonb_array_length(ns)<1 OR jsonb_array_length(ns)>500 OR jsonb_array_length(cv)<>1
 OR octet_length(ns::text)+octet_length(cv::text)>262144 THEN RETURN NULL; END IF;
 SELECT payload INTO captured FROM mto_scope_observations WHERE id=oid AND project_id=pid AND accepted_mto_batch_id=bid;
 IF NOT FOUND OR jsonb_typeof(captured->'rows') IS DISTINCT FROM 'array' OR jsonb_array_length(captured->'rows')>10000 THEN RETURN NULL; END IF;
 FOR n IN SELECT value FROM jsonb_array_elements(ns) LOOP
  IF jsonb_typeof(n) IS DISTINCT FROM 'object' THEN RETURN NULL; END IF;
  SELECT array_agg(k ORDER BY k) INTO keys FROM jsonb_object_keys(n) k;
  IF keys IS DISTINCT FROM ARRAY['id','kind','parent_id','project_id'] THEN RETURN NULL; END IF;
  IF jsonb_typeof(n->'id') IS DISTINCT FROM 'string' OR jsonb_typeof(n->'project_id') IS DISTINCT FROM 'string'
  OR jsonb_typeof(n->'kind') IS DISTINCT FROM 'string' OR (n->>'kind') NOT IN ('PROJECT','FACILITY','AREA','SUBSYSTEM')
  OR (n->>'project_id')::uuid<>pid OR (n->'parent_id'<>'null'::jsonb AND jsonb_typeof(n->'parent_id') IS DISTINCT FROM 'string') THEN RETURN NULL; END IF;
  -- UUID casts below fail closed for invalid raw SQL; application validator rejects them first.
  PERFORM (n->>'id')::uuid;
  IF n->'parent_id'<>'null'::jsonb THEN PERFORM (n->>'parent_id')::uuid; END IF;
 END LOOP;
 SELECT jsonb_agg(jsonb_build_object('id',(v->>'id')::uuid,'project_id',pid,'kind',v->>'kind',
 'parent_id',CASE WHEN v->'parent_id'='null'::jsonb THEN NULL ELSE (v->>'parent_id')::uuid END) ORDER BY v->>'kind',(v->>'id')::uuid)
 INTO canonical_nodes FROM jsonb_array_elements(ns) v;
 SELECT array_agg(v->>'id'),count(DISTINCT v->>'id') INTO ids,node_count FROM jsonb_array_elements(canonical_nodes) v;
 IF node_count<>jsonb_array_length(canonical_nodes) THEN RETURN NULL; END IF;
 IF (SELECT count(*) FROM jsonb_array_elements(canonical_nodes) v WHERE v->>'kind'='PROJECT' AND v->>'id'=pid::text AND v->'parent_id'='null'::jsonb)<>1
 OR (SELECT count(*) FROM jsonb_array_elements(canonical_nodes) v WHERE v->>'kind'='PROJECT')<>1 THEN RETURN NULL; END IF;
 -- This conservative increment cannot admit/drop newly discovered historical identities.
 SELECT jsonb_agg(x ORDER BY x->>'kind',x->>'id') INTO allowed FROM (
 SELECT jsonb_build_object('id',pid,'kind','PROJECT') x
 UNION ALL SELECT jsonb_build_object('id',v->>'id','kind','FACILITY') FROM jsonb_array_elements(captured->'facilities') v
 UNION ALL SELECT jsonb_build_object('id',v->>'id','kind','AREA') FROM jsonb_array_elements(captured->'areas') v
 UNION ALL SELECT jsonb_build_object('id',v->>'id','kind','SUBSYSTEM') FROM jsonb_array_elements(captured->'subsystems') v) q;
 IF (SELECT jsonb_agg(jsonb_build_object('id',v->>'id','kind',v->>'kind') ORDER BY v->>'kind',v->>'id') FROM jsonb_array_elements(canonical_nodes) v) IS DISTINCT FROM allowed THEN RETURN NULL; END IF;
 FOR n IN SELECT value FROM jsonb_array_elements(canonical_nodes) LOOP
  IF n->>'kind'='FACILITY' AND NOT EXISTS(SELECT 1 FROM facilities WHERE id=(n->>'id')::uuid AND project_id=pid) THEN RETURN NULL; END IF;
  IF n->>'kind'='AREA' AND NOT EXISTS(SELECT 1 FROM areas WHERE id=(n->>'id')::uuid AND project_id=pid) THEN RETURN NULL; END IF;
  IF n->>'kind'='SUBSYSTEM' AND NOT EXISTS(SELECT 1 FROM subsystems WHERE id=(n->>'id')::uuid AND project_id=pid) THEN RETURN NULL; END IF;
  IF n->>'kind'='PROJECT' THEN CONTINUE; END IF;
  SELECT value INTO parent_node FROM jsonb_array_elements(canonical_nodes) WHERE value->>'id'=n->>'parent_id';
  IF NOT FOUND OR NOT (CASE n->>'kind' WHEN 'FACILITY' THEN parent_node->>'kind'='PROJECT'
    WHEN 'AREA' THEN parent_node->>'kind'='FACILITY' WHEN 'SUBSYSTEM' THEN parent_node->>'kind' IN ('FACILITY','AREA') ELSE false END) THEN RETURN NULL; END IF;
 END LOOP;
 -- Exactly the declared leaf's ancestry closure; no unrelated visible nodes imply complete coverage.
 cursor_id:=sid::text; seen:=ARRAY[]::text[];
 LOOP
  IF cursor_id=ANY(seen) THEN RETURN NULL; END IF;
  seen:=array_append(seen,cursor_id);
  SELECT value INTO start_node FROM jsonb_array_elements(canonical_nodes) WHERE value->>'id'=cursor_id;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF cursor_id=sid::text AND start_node->>'kind'<>'SUBSYSTEM' THEN RETURN NULL; END IF;
  EXIT WHEN start_node->>'parent_id' IS NULL;
  cursor_id:=start_node->>'parent_id';
 END LOOP;
 IF cursor_id<>pid::text OR cardinality(seen)<>node_count THEN RETURN NULL; END IF;
 c:=cv->0;
 IF jsonb_typeof(c) IS DISTINCT FROM 'object' THEN RETURN NULL; END IF;
 SELECT array_agg(k ORDER BY k) INTO keys FROM jsonb_object_keys(c) k;
 IF keys IS DISTINCT FROM ARRAY['row_ids','scope_id'] OR jsonb_typeof(c->'scope_id') IS DISTINCT FROM 'string'
 OR (c->>'scope_id')::uuid<>sid OR jsonb_typeof(c->'row_ids') IS DISTINCT FROM 'array' THEN RETURN NULL; END IF;
 IF jsonb_array_length(c->'row_ids')<1 OR jsonb_array_length(c->'row_ids')>10000 THEN RETURN NULL; END IF;
 FOR n IN SELECT value FROM jsonb_array_elements(c->'row_ids') LOOP
  IF jsonb_typeof(n) IS DISTINCT FROM 'string' THEN RETURN NULL; END IF;
  PERFORM (n#>>'{}')::uuid;
 END LOOP;
 SELECT jsonb_agg(v ORDER BY v),count(DISTINCT v) INTO canonical_rows,node_count FROM (
 SELECT (value#>>'{}')::uuid::text v FROM jsonb_array_elements(c->'row_ids')) q;
 IF node_count<>jsonb_array_length(c->'row_ids') THEN RETURN NULL; END IF;
 SELECT jsonb_agg(id::text ORDER BY id::text) INTO actual_rows FROM accepted_mto_rows
 WHERE project_id=pid AND accepted_mto_batch_id=bid AND scope_id=sid AND hierarchy_revision_id=pin;
 IF canonical_rows IS DISTINCT FROM actual_rows THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('nodes',canonical_nodes,'coverage',jsonb_build_array(jsonb_build_object('scope_id',sid,'row_ids',canonical_rows)));
END $$;

CREATE OR REPLACE FUNCTION epcc_scope_structure_insert_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
DECLARE p projects%ROWTYPE; e scope_proposal_envelopes%ROWTYPE; b jsonb; claim jsonb;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.state<>'ACTIVE' OR p.archived_at IS NOT NULL
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'SCOPE_STRUCTURE_CONTEXT' USING ERRCODE='42501'; END IF;
 SELECT * INTO e FROM scope_proposal_envelopes WHERE id=NEW.proposal_id AND project_id=NEW.project_id AND company_id=NEW.company_id;
 IF NOT FOUND OR e.proposer_id<>NEW.declared_by OR e.version<>NEW.version OR e.digest<>NEW.proposal_digest
 OR e.digest<>encode(sha256(convert_to(e.envelope::text,'UTF8')),'hex') THEN
 RAISE EXCEPTION 'SCOPE_STRUCTURE_EXACT_AUTHOR_VERSION' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM scope_proposal_envelopes n WHERE n.project_id=e.project_id AND n.company_id=e.company_id AND n.proposer_id=e.proposer_id
 AND n.batch_id=e.batch_id AND n.scope_id=e.scope_id AND n.hierarchy_revision_id=e.hierarchy_revision_id AND n.version>e.version)
 OR EXISTS(SELECT 1 FROM scope_proposal_reviews WHERE proposal_id=e.id AND project_id=e.project_id AND company_id=e.company_id) THEN
 RAISE EXCEPTION 'SCOPE_STRUCTURE_STALE_OR_ALREADY_REVIEWED' USING ERRCODE='23514'; END IF;
 NEW.nomination_policy_id:=epcc_scope_dossier_nomination(e.project_id,e.company_id,NEW.declared_by,e.document_id,e.scope_id,e.hierarchy_revision_id,'propose');
 NEW.reader_policy_id:=epcc_scope_dossier_nomination(e.project_id,e.company_id,NEW.declared_by,e.document_id,e.scope_id,e.hierarchy_revision_id,'read');
 b:=epcc_scope_dossier_binding(e.project_id,e.company_id,NEW.declared_by,e.batch_id,e.document_id,e.scope_id,e.hierarchy_revision_id);
 IF NEW.nomination_policy_id IS NULL OR NEW.reader_policy_id IS NULL OR b IS NULL OR b IS DISTINCT FROM e.binding THEN
 RAISE EXCEPTION 'SCOPE_STRUCTURE_SOURCE_OR_AUTHORITY' USING ERRCODE='42501'; END IF;
 claim:=epcc_scope_structure_claim(e.project_id,e.batch_id,e.scope_id,e.hierarchy_revision_id,e.observation_id,NEW.nodes,NEW.coverage);
 IF claim IS NULL THEN RAISE EXCEPTION 'SCOPE_STRUCTURE_INVALID_OR_NOT_VISIBLE' USING ERRCODE='23514'; END IF;
 NEW.nodes:=claim->'nodes'; NEW.coverage:=claim->'coverage';
 SELECT d.id INTO NEW.previous_id FROM scope_structure_declarations d JOIN scope_proposal_envelopes q ON q.id=d.proposal_id AND q.project_id=d.project_id AND q.company_id=d.company_id
 WHERE q.project_id=e.project_id AND q.company_id=e.company_id AND q.proposer_id=e.proposer_id AND q.batch_id=e.batch_id AND q.scope_id=e.scope_id
 AND q.hierarchy_revision_id=e.hierarchy_revision_id AND q.version<e.version ORDER BY q.version DESC LIMIT 1;
 NEW.created_at:=clock_timestamp();
 NEW.envelope:=jsonb_build_object('schema_version',1,'kind','UNVERIFIED_STRUCTURE_CLAIM','id',NEW.id,'project_id',e.project_id,'company_id',e.company_id,
 'declared_by',NEW.declared_by,'version',NEW.version,'previous_id',NEW.previous_id,'proposal_id',e.id,'proposal_digest',e.digest,'source_binding',b,
 'nodes',NEW.nodes,'coverage',NEW.coverage,'nomination_policy_id',NEW.nomination_policy_id,'reader_policy_id',NEW.reader_policy_id);
 NEW.digest:=encode(sha256(convert_to(NEW.envelope::text,'UTF8')),'hex'); RETURN NEW;
END $$;
CREATE TRIGGER scope_structure_insert_guard BEFORE INSERT ON scope_structure_declarations FOR EACH ROW EXECUTE FUNCTION epcc_scope_structure_insert_guard();

-- Do not let an old narrative-only opinion be presented as covering subsequently added structure.
CREATE OR REPLACE FUNCTION epcc_scope_structure_review_cutoff() RETURNS trigger LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
BEGIN
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM scope_structure_declarations WHERE proposal_id=NEW.proposal_id AND project_id=NEW.project_id AND company_id=NEW.company_id) THEN
 RAISE EXCEPTION 'SCOPE_STRUCTURE_REQUIRES_BOUND_REVIEW' USING ERRCODE='55000'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER aa_scope_structure_review_cutoff BEFORE INSERT ON scope_proposal_reviews FOR EACH ROW EXECUTE FUNCTION epcc_scope_structure_review_cutoff();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS aa_scope_structure_review_cutoff ON scope_proposal_reviews; DROP FUNCTION epcc_scope_structure_review_cutoff();');
        Schema::dropIfExists('scope_structure_declarations');
        DB::unprepared('DROP FUNCTION epcc_scope_structure_insert_guard(); DROP FUNCTION epcc_scope_structure_claim(uuid,uuid,uuid,text,uuid,jsonb,jsonb);');
    }
};
