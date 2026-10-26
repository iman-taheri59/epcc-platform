<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_demand_reader_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p projects%ROWTYPE; last_version integer;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.archived_at IS NOT NULL OR p.state<>'ACTIVE' OR p.owner_company_id<>NEW.company_id
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'DEMAND_READER_CONFIG_SCOPE' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.created_by AND company_id=NEW.company_id AND is_active) THEN
 RAISE EXCEPTION 'DEMAND_READER_ACTOR_SCOPE' USING ERRCODE='42501'; END IF;
 SELECT COALESCE(max(version),0) INTO last_version FROM demand_reader_policies
 WHERE project_id=NEW.project_id AND target_company_id=NEW.target_company_id AND document_id=NEW.document_id
 AND scope_id=NEW.scope_id AND hierarchy_revision_id=NEW.hierarchy_revision_id;
 IF NEW.version<>last_version+1 THEN RAISE EXCEPTION 'DEMAND_READER_STALE_VERSION' USING ERRCODE='23514'; END IF;
 IF NEW.suspended AND NEW.selectors='[]'::jsonb AND last_version>0 AND EXISTS(
 SELECT 1 FROM demand_reader_policies h WHERE h.project_id=NEW.project_id AND h.company_id=NEW.company_id
 AND h.target_company_id=NEW.target_company_id AND h.document_id=NEW.document_id AND h.scope_id=NEW.scope_id
 AND h.hierarchy_revision_id=NEW.hierarchy_revision_id AND h.version=last_version) THEN
 RETURN NEW; -- Reduction of this exact recorded key only; no source reads or new selectors.
 END IF;
 IF NOT EXISTS(SELECT 1 FROM project_company WHERE project_id=NEW.project_id AND company_id=NEW.target_company_id)

 OR NOT EXISTS(SELECT 1 FROM mto_documents WHERE id=NEW.document_id AND project_id=NEW.project_id AND discipline='PIPING')
 OR NOT EXISTS(SELECT 1 FROM accepted_mto_rows r JOIN accepted_mto_batches b ON b.id=r.accepted_mto_batch_id AND b.project_id=r.project_id AND b.mto_document_id=r.mto_document_id AND b.mto_revision_id=r.mto_revision_id
 WHERE r.project_id=NEW.project_id AND r.mto_document_id=NEW.document_id AND r.scope_id=NEW.scope_id
 AND r.hierarchy_revision_id=NEW.hierarchy_revision_id AND b.hierarchy_revision_id=NEW.hierarchy_revision_id AND b.state='ACCEPTED') THEN
 RAISE EXCEPTION 'DEMAND_READER_SOURCE_SCOPE' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_demand_reader_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p projects%ROWTYPE; last_version integer;
BEGIN
 SELECT * INTO p FROM projects WHERE id=NEW.project_id FOR UPDATE;
 IF NOT FOUND OR p.archived_at IS NOT NULL OR p.state<>'ACTIVE' OR p.owner_company_id<>NEW.company_id
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
 RAISE EXCEPTION 'DEMAND_READER_CONFIG_SCOPE' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM project_company WHERE project_id=NEW.project_id AND company_id=NEW.target_company_id)
 OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.created_by AND company_id=NEW.company_id AND is_active)
 OR NOT EXISTS(SELECT 1 FROM mto_documents WHERE id=NEW.document_id AND project_id=NEW.project_id AND discipline='PIPING')
 OR NOT EXISTS(SELECT 1 FROM accepted_mto_rows r JOIN accepted_mto_batches b ON b.id=r.accepted_mto_batch_id AND b.project_id=r.project_id AND b.mto_document_id=r.mto_document_id AND b.mto_revision_id=r.mto_revision_id
 WHERE r.project_id=NEW.project_id AND r.mto_document_id=NEW.document_id AND r.scope_id=NEW.scope_id
 AND r.hierarchy_revision_id=NEW.hierarchy_revision_id AND b.hierarchy_revision_id=NEW.hierarchy_revision_id AND b.state='ACCEPTED') THEN
 RAISE EXCEPTION 'DEMAND_READER_SOURCE_SCOPE' USING ERRCODE='42501'; END IF;
 SELECT COALESCE(max(version),0) INTO last_version FROM demand_reader_policies
 WHERE project_id=NEW.project_id AND target_company_id=NEW.target_company_id AND document_id=NEW.document_id
 AND scope_id=NEW.scope_id AND hierarchy_revision_id=NEW.hierarchy_revision_id;
 IF NEW.version<>last_version+1 THEN RAISE EXCEPTION 'DEMAND_READER_STALE_VERSION' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
    }
};
