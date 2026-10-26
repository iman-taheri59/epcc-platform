-- ISOLATED PROOF ONLY. Synthetic source tables; NOT a Laravel migration.
-- Run only in the dedicated disposable proof database.
CREATE SCHEMA mto_proof AUTHORIZATION mto_proof_owner;
SET ROLE mto_proof_owner;
SET search_path = mto_proof, pg_catalog;
CREATE TABLE projects (
 id uuid PRIMARY KEY, project_id uuid UNIQUE NOT NULL CHECK (id=project_id), archived boolean NOT NULL DEFAULT false
);
CREATE TABLE memberships (
 project_id uuid NOT NULL REFERENCES projects(id), company_id uuid NOT NULL,
 PRIMARY KEY(project_id,company_id)
);
CREATE TABLE clocks (
 project_id uuid PRIMARY KEY REFERENCES projects(id), ordinal bigint NOT NULL CHECK(ordinal>=0)
);
CREATE TABLE batches (
 id uuid PRIMARY KEY, project_id uuid NOT NULL REFERENCES projects(id), document_id uuid NOT NULL,
 revision_id uuid NOT NULL UNIQUE, ordinal bigint NOT NULL CHECK(ordinal>0),
 UNIQUE(project_id,ordinal), UNIQUE(project_id,id,document_id)
);
CREATE TABLE manifest_entries (
 project_id uuid NOT NULL, batch_id uuid NOT NULL, document_id uuid NOT NULL,
 scope_id uuid NOT NULL, hierarchy text NOT NULL CHECK(length(hierarchy)>0),
 PRIMARY KEY(project_id,batch_id,document_id,scope_id,hierarchy),
 FOREIGN KEY(project_id,batch_id,document_id) REFERENCES batches(project_id,id,document_id)
);
CREATE TABLE selection_events (
 id uuid PRIMARY KEY, project_id uuid NOT NULL, document_id uuid NOT NULL,
 scope_id uuid NOT NULL, hierarchy text NOT NULL,
 batch_id uuid NOT NULL, previous_id uuid, step bigint NOT NULL CHECK(step>0),
 CHECK ((previous_id IS NULL)=(step=1)),
 UNIQUE(project_id,document_id,scope_id,hierarchy,id),
 UNIQUE(project_id,document_id,scope_id,hierarchy,step),
 FOREIGN KEY(project_id,batch_id,document_id,scope_id,hierarchy)
  REFERENCES manifest_entries(project_id,batch_id,document_id,scope_id,hierarchy),
 FOREIGN KEY(project_id,document_id,scope_id,hierarchy,previous_id)
  REFERENCES selection_events(project_id,document_id,scope_id,hierarchy,id)
);
CREATE UNIQUE INDEX one_root ON selection_events(project_id,document_id,scope_id,hierarchy) WHERE previous_id IS NULL;
CREATE UNIQUE INDEX one_successor ON selection_events(previous_id) WHERE previous_id IS NOT NULL;

CREATE FUNCTION immutable_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'proof history is immutable' USING ERRCODE='55000'; END; $$;
CREATE FUNCTION writable_project() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM mto_proof.projects WHERE id=NEW.project_id AND NOT archived) THEN
  RAISE EXCEPTION 'project not writable/visible' USING ERRCODE='55000';
 END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION validate_event() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prev_step bigint; prev_ordinal bigint; next_ordinal bigint;
BEGIN
 SELECT b.ordinal INTO next_ordinal FROM mto_proof.batches b
  WHERE b.project_id=NEW.project_id AND b.document_id=NEW.document_id AND b.id=NEW.batch_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'source tuple missing' USING ERRCODE='23503'; END IF;
 IF NEW.previous_id IS NOT NULL THEN
  SELECT e.step,b.ordinal INTO prev_step,prev_ordinal FROM mto_proof.selection_events e
   JOIN mto_proof.batches b ON b.id=e.batch_id AND b.project_id=e.project_id
   WHERE e.id=NEW.previous_id AND e.project_id=NEW.project_id AND e.document_id=NEW.document_id
    AND e.scope_id=NEW.scope_id AND e.hierarchy=NEW.hierarchy;
  IF NOT FOUND THEN RAISE EXCEPTION 'same-key predecessor missing' USING ERRCODE='23503'; END IF;
  IF NEW.step<>prev_step+1 OR next_ordinal<=prev_ordinal THEN
   RAISE EXCEPTION 'step/acceptance must advance' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER event_valid BEFORE INSERT ON selection_events FOR EACH ROW EXECUTE FUNCTION validate_event();

DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['projects','clocks','batches','manifest_entries','selection_events'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format($q$CREATE POLICY tenant ON %I USING (
   project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
   AND EXISTS(SELECT 1 FROM mto_proof.memberships m WHERE m.project_id=%I.project_id
    AND m.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))
   WITH CHECK(project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
   AND EXISTS(SELECT 1 FROM mto_proof.memberships m WHERE m.project_id=%I.project_id
    AND m.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))$q$,t,t,t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['clocks','batches','manifest_entries','selection_events'] LOOP
  EXECUTE format('CREATE TRIGGER writable BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION writable_project()',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['batches','manifest_entries','selection_events'] LOOP
  EXECUTE format('CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION immutable_history()',t);
  EXECUTE format('CREATE POLICY no_update ON %I AS RESTRICTIVE FOR UPDATE USING(false) WITH CHECK(false)',t);
  EXECUTE format('CREATE POLICY no_delete ON %I AS RESTRICTIVE FOR DELETE USING(false)',t);
 END LOOP;
END $$;
ALTER TABLE memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE memberships FORCE ROW LEVEL SECURITY;
CREATE POLICY company ON memberships USING(company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);

-- One complete source tuple + manifest entry + journal event, one scope only.
-- Synthetic fixture writer: no authority, legacy admission or topology engine.
CREATE FUNCTION append_acceptance(p uuid,d uuid,r uuid,s uuid,h text, expected_step bigint DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SET search_path=mto_proof,pg_catalog AS $$
DECLARE old selection_events; n bigint; bid uuid:=gen_random_uuid(); eid uuid:=gen_random_uuid(); active boolean;
BEGIN
 SELECT NOT archived INTO active FROM projects WHERE id=p FOR UPDATE;
 IF NOT FOUND OR NOT active THEN RAISE EXCEPTION 'not writable' USING ERRCODE='55000'; END IF;
 SELECT * INTO old FROM selection_events e WHERE e.project_id=p AND e.document_id=d AND e.scope_id=s AND e.hierarchy=h
  AND NOT EXISTS(SELECT 1 FROM selection_events next WHERE next.previous_id=e.id);
 IF expected_step IS NOT NULL AND expected_step<>COALESCE(old.step,0) THEN
  RAISE EXCEPTION 'stale selection head' USING ERRCODE='40001';
 END IF;
 UPDATE clocks SET ordinal=ordinal+1 WHERE project_id=p RETURNING ordinal INTO n;
 IF NOT FOUND THEN RAISE EXCEPTION 'missing project clock' USING ERRCODE='23503'; END IF;
 INSERT INTO batches VALUES(bid,p,d,r,n);
 INSERT INTO manifest_entries VALUES(p,bid,d,s,h);
 INSERT INTO selection_events VALUES(eid,p,d,s,h,bid,old.id,COALESCE(old.step,0)+1);
 RETURN eid;
END; $$;
REVOKE ALL ON FUNCTION append_acceptance(uuid,uuid,uuid,uuid,text,bigint) FROM PUBLIC;
GRANT USAGE ON SCHEMA mto_proof TO mto_proof_writer;
GRANT SELECT ON ALL TABLES IN SCHEMA mto_proof TO mto_proof_writer;
GRANT UPDATE(id) ON projects TO mto_proof_writer; -- row-lock privilege, not archive permission
GRANT UPDATE(ordinal) ON clocks TO mto_proof_writer;
GRANT INSERT ON batches,manifest_entries,selection_events TO mto_proof_writer;
GRANT EXECUTE ON FUNCTION append_acceptance(uuid,uuid,uuid,uuid,text,bigint) TO mto_proof_writer;
RESET ROLE;
-- This proof's deliberately bounded shape is ONE covered scope per batch.
SET ROLE mto_proof_owner;
SET search_path=mto_proof,pg_catalog;
ALTER TABLE manifest_entries ADD UNIQUE(batch_id);
ALTER TABLE selection_events ADD UNIQUE(batch_id);
CREATE FUNCTION complete_group() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE bid uuid; p uuid;
BEGIN
 IF TG_TABLE_NAME='batches' THEN bid:=NEW.id; ELSE bid:=NEW.batch_id; END IF;
 p:=NEW.project_id;
 IF (SELECT count(*) FROM mto_proof.manifest_entries WHERE batch_id=bid AND project_id=p)<>1
 OR (SELECT count(*) FROM mto_proof.selection_events WHERE batch_id=bid AND project_id=p)<>1 THEN
  RAISE EXCEPTION 'incomplete acceptance group' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER batch_complete AFTER INSERT ON batches DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION complete_group();
CREATE CONSTRAINT TRIGGER manifest_complete AFTER INSERT ON manifest_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION complete_group();
CREATE CONSTRAINT TRIGGER event_complete AFTER INSERT ON selection_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION complete_group();
RESET ROLE;
