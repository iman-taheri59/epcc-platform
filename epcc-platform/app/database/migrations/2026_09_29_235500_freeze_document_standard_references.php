<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-016 §10: capture in the publication statement, not a later callback. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_citations', function (Blueprint $table): void {
            $table->string('source_edition', 32)->nullable();
        });
        Schema::table('document_revisions', function (Blueprint $table): void {
            // NULL is deliberately retained for pre-migration history.
            $table->jsonb('standard_reference_snapshot')->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION dr01_assert_open_document(did uuid, pid uuid, cid uuid)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE d documents%ROWTYPE; r document_revisions%ROWTYPE;
BEGIN
    IF did IS NULL THEN RETURN; END IF;
    SELECT * INTO d FROM documents WHERE id = did;
    IF NOT FOUND OR d.project_id <> pid OR d.company_id <> cid THEN
        RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
    END IF;
    -- All reference mutations and publication contend on the same revision.
    SELECT * INTO r FROM document_revisions WHERE id = d.current_revision_id FOR UPDATE;
    IF NOT FOUND OR r.document_id <> did OR r.project_id <> pid THEN
        RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
    END IF;
    IF r.cde_state IN ('PUBLISHED', 'ARCHIVED') THEN
        RAISE EXCEPTION 'DR01_REFERENCES_LOCKED' USING ERRCODE = '23514';
    END IF;
END $$;

CREATE OR REPLACE FUNCTION dr01_assert_open_itp(iid uuid, pid uuid)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE i itps%ROWTYPE;
BEGIN
    SELECT * INTO i FROM itps WHERE id = iid FOR UPDATE;
    IF NOT FOUND OR i.project_id <> pid OR
       i.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid THEN
        RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
    END IF;
    PERFORM dr01_assert_open_document(i.document_id, i.project_id, i.company_id);
END $$;

CREATE OR REPLACE FUNCTION dr01_guard_citation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c record; s itp_stages%ROWTYPE;
BEGIN
    -- Validate BOTH ends of moves, not merely the new parent.
    FOR c IN SELECT * FROM jsonb_to_recordset(
        CASE TG_OP WHEN 'INSERT' THEN jsonb_build_array(to_jsonb(NEW))
                   WHEN 'DELETE' THEN jsonb_build_array(to_jsonb(OLD))
                   ELSE jsonb_build_array(to_jsonb(OLD), to_jsonb(NEW)) END
    ) AS x(citable_type text, citable_id uuid, project_id uuid) LOOP
        IF c.citable_type <> 'ITP_STAGE' THEN
            RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
        END IF;
        SELECT * INTO s FROM itp_stages WHERE id = c.citable_id FOR SHARE;
        IF NOT FOUND OR s.project_id <> c.project_id THEN
            RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
        END IF;
        PERFORM dr01_assert_open_itp(s.itp_id, s.project_id);
    END LOOP;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER dr01_citation_guard BEFORE INSERT OR UPDATE OR DELETE ON standard_citations
FOR EACH ROW EXECUTE FUNCTION dr01_guard_citation();

CREATE OR REPLACE FUNCTION dr01_guard_stage() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Parent deletion was checked BEFORE DELETE while the ITP still existed.
    -- Its polymorphic citations were removed there, before FK cascades start.
    IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM itps WHERE id = OLD.itp_id) THEN
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.reference, NEW.itp_id, NEW.project_id, NEW.company_id, NEW.id)
           IS NOT DISTINCT FROM
           (OLD.reference, OLD.itp_id, OLD.project_id, OLD.company_id, OLD.id) THEN RETURN NEW; END IF;
    END IF;
    IF TG_OP <> 'INSERT' THEN PERFORM dr01_assert_open_itp(OLD.itp_id, OLD.project_id); END IF;
    IF TG_OP <> 'DELETE' THEN
        PERFORM dr01_assert_open_itp(NEW.itp_id, NEW.project_id);
        IF NOT EXISTS (SELECT 1 FROM itps WHERE id = NEW.itp_id AND company_id = NEW.company_id) THEN
            RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
        END IF;
        IF TG_OP = 'UPDATE' AND (NEW.id, NEW.project_id, NEW.itp_id) IS DISTINCT FROM (OLD.id, OLD.project_id, OLD.itp_id)
            AND EXISTS (SELECT 1 FROM standard_citations WHERE citable_type = 'ITP_STAGE' AND citable_id = OLD.id) THEN
            RAISE EXCEPTION 'DR01_HOST_MISMATCH' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    -- Polymorphic links have no FK; never leave orphan references behind.
    IF EXISTS (SELECT 1 FROM standard_citations WHERE citable_type = 'ITP_STAGE' AND citable_id = OLD.id) THEN
        RAISE EXCEPTION 'DR01_HOST_HAS_REFERENCES' USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END $$;
CREATE TRIGGER dr01_stage_guard BEFORE INSERT OR UPDATE OR DELETE ON itp_stages
FOR EACH ROW EXECUTE FUNCTION dr01_guard_stage();

CREATE OR REPLACE FUNCTION dr01_guard_itp() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.document_id, NEW.project_id, NEW.company_id, NEW.id)
        IS NOT DISTINCT FROM (OLD.document_id, OLD.project_id, OLD.company_id, OLD.id) THEN RETURN NEW; END IF;
    IF TG_OP <> 'INSERT' THEN
        PERFORM dr01_assert_open_document(OLD.document_id, OLD.project_id, OLD.company_id);
    END IF;
    IF TG_OP <> 'DELETE' THEN
        PERFORM dr01_assert_open_document(NEW.document_id, NEW.project_id, NEW.company_id);
        RETURN NEW;
    END IF;
    DELETE FROM standard_citations WHERE citable_type = 'ITP_STAGE'
        AND citable_id IN (SELECT id FROM itp_stages WHERE itp_id = OLD.id);
    RETURN OLD;
END $$;
CREATE TRIGGER dr01_itp_guard BEFORE INSERT OR UPDATE OR DELETE ON itps
FOR EACH ROW EXECUTE FUNCTION dr01_guard_itp();

CREATE OR REPLACE FUNCTION dr01_capture_basis(did uuid, pid uuid) RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE payload jsonb; stage jsonb; citation jsonb;
BEGIN
    SELECT jsonb_build_object('schema_version', 1, 'scope', 'ITP_STAGE',
        'document_id', did, 'project_id', pid, 'captured_at', transaction_timestamp(),
        'stages', COALESCE(jsonb_agg(jsonb_build_object(
            'itp_id', i.id, 'stage_id', s.id, 'stage_clause', s.clause, 'sequence', s.sequence,
            'unresolved_reference', s.reference,
            'citations', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                'citation_id', c.id, 'standard_id', st.id, 'body', st.body,
                'designation', st.designation, 'normalised', st.normalised,
                'title_en', st.title_en, 'title_fa', st.title_fa,
                'edition', COALESCE(NULLIF(btrim(c.source_edition), ''), NULLIF(btrim(ps.edition), '')),
                'edition_source', CASE WHEN NULLIF(btrim(c.source_edition), '') IS NOT NULL THEN 'CITATION_SOURCE' ELSE 'PROJECT_EDITION' END,
                'clause', c.clause, 'sequence', c.sequence
            ) ORDER BY c.sequence, c.id) FROM standard_citations c
              JOIN standards st ON st.id = c.standard_id
              LEFT JOIN project_standards ps ON ps.standard_id = c.standard_id AND ps.project_id = c.project_id
              WHERE c.citable_type = 'ITP_STAGE' AND c.citable_id = s.id AND c.project_id = pid), '[]'::jsonb)
        ) ORDER BY i.id, s.sequence, s.id), '[]'::jsonb)) INTO payload
    FROM itps i JOIN itp_stages s ON s.itp_id = i.id
    WHERE i.document_id = did AND i.project_id = pid AND s.project_id = pid;
    FOR stage IN SELECT value FROM jsonb_array_elements(payload->'stages') LOOP
        IF NULLIF(btrim(stage->>'unresolved_reference'), '') IS NOT NULL THEN
            RAISE EXCEPTION 'DR01_UNRESOLVED_REFERENCE: %', stage->>'stage_clause' USING ERRCODE = '23514';
        END IF;
        FOR citation IN SELECT value FROM jsonb_array_elements(stage->'citations') LOOP
            IF NULLIF(btrim(citation->>'edition'), '') IS NULL THEN
                RAISE EXCEPTION 'DR01_MISSING_EDITION: % %', citation->>'body', citation->>'designation' USING ERRCODE = '23514';
            END IF;
        END LOOP;
    END LOOP;
    RETURN payload;
END $$;

CREATE OR REPLACE FUNCTION dr01_revision_basis() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        IF OLD.cde_state IN ('PUBLISHED', 'ARCHIVED') OR OLD.standard_reference_snapshot IS NOT NULL THEN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'DR01_HISTORY_IMMUTABLE' USING ERRCODE = '23514';
            END IF;
            IF (NEW.standard_reference_snapshot, NEW.document_id, NEW.project_id, NEW.company_id, NEW.id, NEW.revision, NEW.ordinal)
               IS DISTINCT FROM
               (OLD.standard_reference_snapshot, OLD.document_id, OLD.project_id, OLD.company_id, OLD.id, OLD.revision, OLD.ordinal)
               OR NEW.cde_state NOT IN ('PUBLISHED', 'ARCHIVED')
               OR (OLD.cde_state = 'ARCHIVED' AND NEW.cde_state <> 'ARCHIVED') THEN
                RAISE EXCEPTION 'DR01_HISTORY_IMMUTABLE' USING ERRCODE = '23514';
            END IF;
            RETURN NEW; -- including legacy NULL: never fabricate a past snapshot.
        END IF;
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    END IF;
    IF NEW.standard_reference_snapshot IS NOT NULL THEN
        RAISE EXCEPTION 'DR01_SNAPSHOT_SYSTEM_MANAGED' USING ERRCODE = '23514';
    END IF;
    IF NEW.cde_state = 'PUBLISHED' THEN
        NEW.standard_reference_snapshot := dr01_capture_basis(NEW.document_id, NEW.project_id);
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER dr01_revision_snapshot BEFORE INSERT OR UPDATE OR DELETE ON document_revisions
FOR EACH ROW EXECUTE FUNCTION dr01_revision_basis();
SQL);
    }

    public function down(): void
    {
        // Destructive rollback would erase accepted historical evidence.
        throw new RuntimeException('DR-01 is forward-only: do not erase frozen reference history.');
    }
};
