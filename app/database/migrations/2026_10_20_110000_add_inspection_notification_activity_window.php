<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE inspection_notifications ADD COLUMN activity_start_input varchar(35), ADD COLUMN activity_end_input varchar(35), ADD COLUMN activity_start_at timestamptz, ADD COLUMN activity_end_at timestamptz;
ALTER TABLE inspection_notifications ALTER COLUMN scheduled_input DROP NOT NULL, ALTER COLUMN scheduled_at DROP NOT NULL;
-- Legacy rows keep their exact single time; never invent an end or backfill a window.
ALTER TABLE inspection_notifications ADD CONSTRAINT notification_activity_schedule CHECK (
 (activity_start_input IS NULL AND activity_end_input IS NULL AND activity_start_at IS NULL AND activity_end_at IS NULL AND scheduled_input IS NOT NULL AND scheduled_at IS NOT NULL)
 OR (activity_start_input IS NOT NULL AND activity_end_input IS NOT NULL AND activity_start_at IS NOT NULL AND activity_end_at IS NOT NULL AND activity_end_at>activity_start_at AND scheduled_input IS NULL AND scheduled_at IS NULL));
CREATE OR REPLACE FUNCTION epcc_guard_notification() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record; rev record; sid uuid; maxseq integer;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'NOTIFICATION_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('inspection-notification:'||NEW.project_id::text,0));
 IF NOT epcc_project_is_writable(NEW.project_id) OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN RAISE EXCEPTION 'NOTIFICATION_SCOPE' USING ERRCODE='42501'; END IF;
 IF TG_TABLE_NAME='inspection_notification_settings' THEN
  IF TG_OP<>'INSERT' OR NOT epcc_notification_cap(NEW.project_id,NEW.created_by,NEW.company_id,'project.configure') THEN RAISE EXCEPTION 'NOTIFICATION_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
  SELECT COALESCE(max(version),0) INTO maxseq FROM inspection_notification_settings WHERE project_id=NEW.project_id;
  IF NEW.version<>maxseq+1 OR NOT EXISTS(SELECT 1 FROM project_company pc JOIN companies c ON c.id=pc.company_id WHERE pc.project_id=NEW.project_id AND pc.company_id=NEW.main_company_id AND c.tenant_type='EPC') THEN RAISE EXCEPTION 'NOTIFICATION_CONFIG_STALE' USING ERRCODE='23514'; END IF;
  NEW.created_at:=CURRENT_TIMESTAMP; RETURN NEW;
 END IF;
 IF TG_TABLE_NAME='inspection_itp_stages' THEN
  IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'NOTIFICATION_STAGE_IMMUTABLE' USING ERRCODE='23514'; END IF;
  SELECT d.discipline,d.company_id INTO rev FROM document_revisions r JOIN documents d ON d.id=r.document_id WHERE r.id=NEW.revision_id AND r.project_id=NEW.project_id AND d.project_id=NEW.project_id AND d.doc_type='ITP' AND d.company_id=NEW.company_id;
  IF NOT FOUND OR NOT epcc_notification_cap(NEW.project_id,NEW.created_by,NEW.company_id,'doc.issue',rev.discipline) THEN RAISE EXCEPTION 'NOTIFICATION_ITP_FORBIDDEN' USING ERRCODE='42501'; END IF;
  NEW.discipline:=rev.discipline; NEW.created_at:=CURRENT_TIMESTAMP; RETURN NEW;
 END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.state<>'DRAFT' OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'NOTIFICATION_IMMUTABLE' USING ERRCODE='23514'; END IF;
  IF NEW.state='DRAFT' THEN
   IF (to_jsonb(NEW)-ARRAY['version','work_reference','location','scheduled_input','scheduled_at','activity_start_input','activity_end_input','activity_start_at','activity_end_at','recipient_company_ids','purchase_order_id','notes_en','notes_fa','last_edited_by','last_edited_at','last_edit_setting_id']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['version','work_reference','location','scheduled_input','scheduled_at','activity_start_input','activity_end_input','activity_start_at','activity_end_at','recipient_company_ids','purchase_order_id','notes_en','notes_fa','last_edited_by','last_edited_at','last_edit_setting_id']) THEN RAISE EXCEPTION 'NOTIFICATION_IMMUTABLE' USING ERRCODE='23514'; END IF;
  ELSIF NEW.state<>'ISSUED' OR (to_jsonb(NEW)-ARRAY['state','version','sequence','number','issued_by','issued_at','issue_setting_id','issued_snapshot']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','version','sequence','number','issued_by','issued_at','issue_setting_id','issued_snapshot']) THEN RAISE EXCEPTION 'NOTIFICATION_IMMUTABLE' USING ERRCODE='23514'; END IF;
 END IF;
 -- New drafts, edits (including legacy drafts) and every new issue require a window.
 IF NEW.activity_start_input IS NULL OR NEW.activity_end_input IS NULL OR NEW.activity_start_at IS NULL OR NEW.activity_end_at IS NULL THEN RAISE EXCEPTION 'NOTIFICATION_ACTIVITY_WINDOW_REQUIRED' USING ERRCODE='23514'; END IF;
 IF NEW.scheduled_input IS NOT NULL OR NEW.scheduled_at IS NOT NULL OR NEW.activity_end_at<=NEW.activity_start_at
 OR NEW.activity_start_input !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}$'
 OR NEW.activity_end_input !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}$'
 THEN RAISE EXCEPTION 'NOTIFICATION_ACTIVITY_WINDOW_INVALID' USING ERRCODE='23514'; END IF;
 BEGIN
  IF NEW.activity_start_input::timestamptz IS DISTINCT FROM NEW.activity_start_at OR NEW.activity_end_input::timestamptz IS DISTINCT FROM NEW.activity_end_at THEN RAISE EXCEPTION 'NOTIFICATION_ACTIVITY_WINDOW_INVALID' USING ERRCODE='23514'; END IF;
 EXCEPTION WHEN SQLSTATE '22007' OR SQLSTATE '22008' OR SQLSTATE '22009' THEN RAISE EXCEPTION 'NOTIFICATION_ACTIVITY_WINDOW_INVALID' USING ERRCODE='23514'; END;
 SELECT * INTO s FROM inspection_itp_stages WHERE id=NEW.stage_id AND project_id=NEW.project_id AND requires_notification;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_STAGE_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 SELECT r.id,d.discipline INTO rev FROM document_revisions r JOIN documents d ON d.id=r.document_id WHERE r.id=s.revision_id AND r.project_id=NEW.project_id AND d.project_id=NEW.project_id AND d.doc_type='ITP' AND r.cde_state<>'ARCHIVED';
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_ITP_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 IF NEW.discipline IS DISTINCT FROM s.discipline OR rev.discipline IS DISTINCT FROM s.discipline THEN RAISE EXCEPTION 'NOTIFICATION_DISCIPLINE' USING ERRCODE='23514'; END IF;
 IF NEW.purchase_order_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.project_id=NEW.project_id AND p.discipline=s.discipline) THEN RAISE EXCEPTION 'NOTIFICATION_PO_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements_text(NEW.recipient_company_ids) x WHERE NOT EXISTS(SELECT 1 FROM project_company pc JOIN companies c ON c.id=pc.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects pr ON pr.id=pc.project_id WHERE pc.project_id=NEW.project_id AND pc.company_id::text=x AND (jsonb_exists(pl.modules::jsonb,'qaqc') OR jsonb_exists(pl.modules::jsonb,'*')) AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*')))) THEN RAISE EXCEPTION 'NOTIFICATION_RECIPIENT_INVALID' USING ERRCODE='23514'; END IF;
 sid:=epcc_notification_setting(NEW.project_id,CASE WHEN TG_OP='INSERT' THEN NEW.created_by WHEN NEW.state='DRAFT' THEN NEW.last_edited_by ELSE NEW.issued_by END,NEW.company_id,CASE WHEN TG_OP='INSERT' OR NEW.state='DRAFT' THEN 'draft' ELSE 'issue' END,s.discipline);
 IF sid IS NULL OR NOT epcc_notification_cap(NEW.project_id,CASE WHEN TG_OP='INSERT' THEN NEW.created_by WHEN NEW.state='DRAFT' THEN NEW.last_edited_by ELSE NEW.issued_by END,NEW.company_id,'doc.view',s.discipline) THEN RAISE EXCEPTION 'NOTIFICATION_PERMISSION_REVOKED' USING ERRCODE='42501'; END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.state<>'DRAFT' OR NEW.version<>1 OR NEW.last_edited_by IS NOT NULL OR NEW.last_edit_setting_id IS NOT NULL OR NEW.last_edited_at IS NOT NULL THEN RAISE EXCEPTION 'NOTIFICATION_CREATE_DRAFT_ONLY' USING ERRCODE='23514'; END IF;
  NEW.entry_setting_id:=sid; NEW.created_at:=CURRENT_TIMESTAMP;
 ELSIF NEW.state='DRAFT' THEN
  NEW.last_edit_setting_id:=sid; NEW.last_edited_at:=CURRENT_TIMESTAMP;
 ELSE
  NEW.issue_setting_id:=sid; NEW.issued_at:=CURRENT_TIMESTAMP;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: an activity window must not be collapsed into a fabricated inspection time.');
    }
};
