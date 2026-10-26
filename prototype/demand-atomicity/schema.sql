-- ISOLATED TECHNICAL PROOF ONLY. Never install in the application database.
CREATE SCHEMA demand_proof;
SET search_path=demand_proof,pg_catalog;
CREATE TABLE projects(id text PRIMARY KEY, buyer text NOT NULL, archived boolean NOT NULL DEFAULT false);
CREATE TABLE sources(project text NOT NULL REFERENCES projects(id), buyer text NOT NULL, id text NOT NULL,
 basis text NOT NULL, evidence text NOT NULL, admitted boolean NOT NULL, inventory_complete boolean NOT NULL,
 gross bigint NOT NULL CHECK(gross>0), used bigint NOT NULL DEFAULT 0 CHECK(used>=0 AND used<=gross), PRIMARY KEY(project,id));
CREATE TABLE nominations(project text NOT NULL REFERENCES projects(id),buyer text NOT NULL,actor text NOT NULL,source text NOT NULL,
 active boolean NOT NULL,can_read boolean NOT NULL,can_apply boolean NOT NULL,can_commercial boolean NOT NULL,
 PRIMARY KEY(project,actor,source), FOREIGN KEY(project,source) REFERENCES sources(project,id));
CREATE TABLE mrs(project text NOT NULL REFERENCES projects(id),buyer text NOT NULL,id text NOT NULL,version text NOT NULL,
 source text NOT NULL,qty bigint NOT NULL CHECK(qty>0),state text NOT NULL CHECK(state IN ('TECHNICAL_APPROVED','APPROVED')),
 raised_by text NOT NULL,technical_by text NOT NULL,proposed_by text NOT NULL,reviewed_by text NOT NULL,
 reviewed_version text NOT NULL,reviewed_basis text NOT NULL,reviewed_evidence text NOT NULL,commercial_by text,PRIMARY KEY(project,id),
 FOREIGN KEY(project,source) REFERENCES sources(project,id));
CREATE TABLE events(project text NOT NULL,buyer text NOT NULL,request text NOT NULL,payload_hash text NOT NULL,
 mr text NOT NULL,source text NOT NULL,qty bigint NOT NULL CHECK(qty>0),basis text NOT NULL,evidence text NOT NULL,actor text NOT NULL,
 PRIMARY KEY(project,buyer,request),UNIQUE(project,mr), FOREIGN KEY(project,mr) REFERENCES mrs(project,id),
 FOREIGN KEY(project,source) REFERENCES sources(project,id));
DO $$DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['projects','sources','nominations','mrs','events'] LOOP
 EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
 EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
 IF t='projects' THEN
 EXECUTE format('CREATE POLICY scope ON %I USING (id=current_setting(''proof.project'',true) AND buyer=current_setting(''proof.buyer'',true))',t);
 ELSE
 EXECUTE format('CREATE POLICY scope ON %I USING (project=current_setting(''proof.project'',true) AND buyer=current_setting(''proof.buyer'',true))',t);
 END IF;
 END LOOP;
END$$;
CREATE FUNCTION immutable_event() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'IMMUTABLE_EVENT'; END $$;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON events FOR EACH ROW EXECUTE FUNCTION immutable_event();
CREATE FUNCTION approve_and_reserve(mid text, expected_mr text, expected_basis text, expected_evidence text, rid text, fault text DEFAULT '')
RETURNS text LANGUAGE plpgsql SECURITY INVOKER AS $$
DECLARE p projects%ROWTYPE;m mrs%ROWTYPE;s sources%ROWTYPE;n nominations%ROWTYPE;e events%ROWTYPE;
 actor text:=current_setting('proof.actor',true); digest text;
BEGIN
 SELECT * INTO p FROM projects WHERE id=current_setting('proof.project',true) FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'PROJECT_UNAVAILABLE'; END IF;
 IF p.archived THEN RAISE EXCEPTION 'ARCHIVED'; END IF;
 SELECT * INTO m FROM mrs WHERE project=p.id AND id=mid FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'MR_UNAVAILABLE'; END IF;
 SELECT * INTO s FROM sources WHERE project=p.id AND id=m.source FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'SOURCE_UNAVAILABLE'; END IF;
 SELECT n0.* INTO n FROM nominations n0 WHERE n0.project=p.id AND n0.actor=actor_id() AND n0.source=s.id;
 IF NOT FOUND OR NOT n.active OR NOT n.can_read OR NOT n.can_apply OR NOT n.can_commercial THEN RAISE EXCEPTION 'CURRENT_AUTHORITY_REQUIRED'; END IF;
 digest:=encode(sha256(convert_to(jsonb_build_object('mr',mid,'mr_version',expected_mr,'basis',expected_basis,
 'evidence',expected_evidence,'actor',actor,'qty',m.qty)::text,'UTF8')),'hex');
 SELECT * INTO e FROM events WHERE project=p.id AND buyer=p.buyer AND request=rid;
 IF FOUND THEN
 IF e.payload_hash<>digest THEN RAISE EXCEPTION 'RETRY_PAYLOAD_CONFLICT'; END IF;
 RETURN e.request;
 END IF;
 IF m.version<>expected_mr THEN RAISE EXCEPTION 'MR_VERSION_CHANGED'; END IF;
 IF s.basis<>expected_basis OR s.evidence<>expected_evidence THEN RAISE EXCEPTION 'SOURCE_HEAD_CHANGED'; END IF;
 IF NOT s.admitted OR NOT s.inventory_complete THEN RAISE EXCEPTION 'DEMAND_UNRESOLVED'; END IF;
 IF m.state<>'TECHNICAL_APPROVED' THEN RAISE EXCEPTION 'INVALID_MR_STATE'; END IF;
 IF actor=m.raised_by OR actor=m.technical_by THEN RAISE EXCEPTION 'MR_SOD'; END IF;
 IF m.proposed_by=m.reviewed_by OR actor NOT IN(m.proposed_by,m.reviewed_by)
 OR m.reviewed_version<>m.version OR m.reviewed_basis<>s.basis OR m.reviewed_evidence<>s.evidence THEN RAISE EXCEPTION 'REVIEW_REQUIRED'; END IF;
 IF m.qty>s.gross-s.used THEN RAISE EXCEPTION 'DEMAND_EXHAUSTED'; END IF;
 UPDATE mrs SET state='APPROVED',commercial_by=actor WHERE project=p.id AND id=m.id;
 IF fault='after_approval' THEN RAISE EXCEPTION 'INJECTED_AFTER_APPROVAL'; END IF;
 UPDATE sources SET used=used+m.qty WHERE project=p.id AND id=s.id;
 INSERT INTO events VALUES(p.id,p.buyer,rid,digest,m.id,s.id,m.qty,s.basis,s.evidence,actor);
 IF fault='after_event' THEN RAISE EXCEPTION 'INJECTED_AFTER_EVENT'; END IF;
 RETURN rid;
END $$;
CREATE FUNCTION actor_id() RETURNS text LANGUAGE sql STABLE SECURITY INVOKER AS $$ SELECT current_setting('proof.actor',true) $$;
REVOKE ALL ON FUNCTION approve_and_reserve(text,text,text,text,text,text) FROM PUBLIC;
GRANT USAGE ON SCHEMA demand_proof TO demand_atomic_actor;
GRANT SELECT ON ALL TABLES IN SCHEMA demand_proof TO demand_atomic_actor;
GRANT UPDATE ON projects,sources,mrs TO demand_atomic_actor;
GRANT INSERT ON events TO demand_atomic_actor;
GRANT EXECUTE ON FUNCTION approve_and_reserve(text,text,text,text,text,text) TO demand_atomic_actor;
