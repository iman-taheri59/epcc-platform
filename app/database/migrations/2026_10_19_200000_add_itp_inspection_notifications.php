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
        Schema::create('inspection_notification_settings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('main_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->integer('version');
            $t->jsonb('draft_users');
            $t->jsonb('issue_users');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'version']);
        });
        Schema::create('inspection_itp_stages', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('revision_id')->constrained('document_revisions')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->integer('sequence');
            $t->string('discipline', 32);
            $t->string('point', 1);
            $t->boolean('requires_notification');
            $t->text('description_en')->nullable();
            $t->text('description_fa')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['revision_id', 'sequence']);
        });
        Schema::create('inspection_notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('stage_id')->constrained('inspection_itp_stages')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('entry_setting_id')->constrained('inspection_notification_settings')->restrictOnDelete();
            $t->foreignUuid('issue_setting_id')->nullable()->constrained('inspection_notification_settings')->restrictOnDelete();
            $t->string('discipline', 32);
            $t->string('state', 16)->default('DRAFT');
            $t->integer('version')->default(1);
            $t->integer('sequence')->nullable();
            $t->string('number', 128)->nullable();
            $t->text('work_reference');
            $t->text('location');
            $t->string('scheduled_input', 40);
            $t->timestampTz('scheduled_at');
            $t->jsonb('recipient_company_ids');
            $t->text('notes_en')->nullable();
            $t->text('notes_fa')->nullable();
            $t->jsonb('issued_snapshot')->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('issued_at')->nullable();
            $t->unique(['project_id', 'number']);
            $t->unique(['project_id', 'company_id', 'sequence'], 'notification_company_sequence_unique');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE inspection_notification_settings ADD CHECK (version>0 AND jsonb_typeof(draft_users)='array' AND jsonb_typeof(issue_users)='array' AND length(btrim(reason))>0);
ALTER TABLE inspection_itp_stages ADD CHECK (sequence>0 AND point IN ('H','W','R','M') AND length(btrim(COALESCE(description_en,'') || COALESCE(description_fa,'')))>0);
ALTER TABLE inspection_notifications ADD CHECK (state IN ('DRAFT','ISSUED') AND version>0 AND jsonb_typeof(recipient_company_ids)='array' AND jsonb_array_length(recipient_company_ids)>0);
ALTER TABLE inspection_notifications ADD CHECK ((state='DRAFT' AND version=1 AND number IS NULL AND sequence IS NULL AND issued_at IS NULL AND issued_by IS NULL AND issue_setting_id IS NULL AND issued_snapshot IS NULL) OR (state='ISSUED' AND version=2 AND number IS NOT NULL AND sequence>0 AND issued_at IS NOT NULL AND issued_by IS NOT NULL AND issue_setting_id IS NOT NULL AND issued_snapshot IS NOT NULL));
CREATE OR REPLACE FUNCTION epcc_notification_cap(pid uuid,aid uuid,cid uuid,cap text,disc text DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM users u JOIN project_memberships m ON m.user_id=u.id
 JOIN companies c ON c.id=u.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects pr ON pr.id=m.project_id
 WHERE u.id=aid AND u.company_id=cid AND u.is_active AND m.project_id=pid
 AND (jsonb_exists(pl.modules::jsonb,'qaqc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'))
 AND (cap NOT LIKE 'doc.%' OR jsonb_exists(pl.modules::jsonb,'doc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (disc IS NULL OR m.discipline_scope IS NULL OR jsonb_exists(m.discipline_scope,disc))
 AND EXISTS(SELECT 1 FROM roles r WHERE jsonb_exists(m.role_codes,r.code)
 AND (jsonb_exists(r.capabilities,cap) OR jsonb_exists(r.capabilities,split_part(cap,'.',1)||'.*') OR jsonb_exists(r.capabilities,'*'))));
$$;
CREATE OR REPLACE FUNCTION epcc_notification_setting(pid uuid,aid uuid,cid uuid,purpose text,disc text)
RETURNS uuid LANGUAGE sql STABLE AS $$
 WITH latest AS (SELECT * FROM inspection_notification_settings WHERE project_id=pid ORDER BY version DESC LIMIT 1)
 SELECT latest.id FROM latest,users u JOIN project_memberships m ON m.user_id=u.id
 JOIN companies c ON c.id=u.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects pr ON pr.id=m.project_id
 WHERE u.id=aid AND u.company_id=cid AND u.is_active AND m.project_id=pid AND c.tenant_type='EPC'
 AND latest.main_company_id=cid AND epcc_project_is_writable(pid)
 AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
 AND (jsonb_exists(pl.modules::jsonb,'qaqc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.modules::jsonb,'doc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'))
 AND (m.discipline_scope IS NULL OR (disc IS NOT NULL AND jsonb_exists(m.discipline_scope,disc)))
 AND ((purpose='draft' AND jsonb_exists(latest.draft_users,aid::text)) OR (purpose='issue' AND jsonb_exists(latest.issue_users,aid::text)));
$$;
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
 IF TG_OP='UPDATE' AND (OLD.state<>'DRAFT' OR NEW.state<>'ISSUED' OR (to_jsonb(NEW)-ARRAY['state','version','sequence','number','issued_by','issued_at','issue_setting_id','issued_snapshot']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','version','sequence','number','issued_by','issued_at','issue_setting_id','issued_snapshot'])) THEN RAISE EXCEPTION 'NOTIFICATION_IMMUTABLE' USING ERRCODE='23514'; END IF;
 SELECT * INTO s FROM inspection_itp_stages WHERE id=NEW.stage_id AND project_id=NEW.project_id AND requires_notification;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_STAGE_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 SELECT r.id,d.discipline INTO rev FROM document_revisions r JOIN documents d ON d.id=r.document_id WHERE r.id=s.revision_id AND r.project_id=NEW.project_id AND d.project_id=NEW.project_id AND d.doc_type='ITP' AND r.cde_state<>'ARCHIVED';
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_ITP_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 IF NEW.discipline IS DISTINCT FROM s.discipline OR rev.discipline IS DISTINCT FROM s.discipline THEN RAISE EXCEPTION 'NOTIFICATION_DISCIPLINE' USING ERRCODE='23514'; END IF;
 IF NEW.purchase_order_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.project_id=NEW.project_id AND p.discipline=s.discipline) THEN RAISE EXCEPTION 'NOTIFICATION_PO_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements_text(NEW.recipient_company_ids) x WHERE NOT EXISTS(SELECT 1 FROM project_company pc JOIN companies c ON c.id=pc.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects pr ON pr.id=pc.project_id WHERE pc.project_id=NEW.project_id AND pc.company_id::text=x AND (jsonb_exists(pl.modules::jsonb,'qaqc') OR jsonb_exists(pl.modules::jsonb,'*')) AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*')))) THEN RAISE EXCEPTION 'NOTIFICATION_RECIPIENT_INVALID' USING ERRCODE='23514'; END IF;
 sid:=epcc_notification_setting(NEW.project_id,CASE WHEN TG_OP='INSERT' THEN NEW.created_by ELSE NEW.issued_by END,NEW.company_id,CASE WHEN TG_OP='INSERT' THEN 'draft' ELSE 'issue' END,s.discipline);
 IF sid IS NULL OR NOT epcc_notification_cap(NEW.project_id,CASE WHEN TG_OP='INSERT' THEN NEW.created_by ELSE NEW.issued_by END,NEW.company_id,'doc.view',s.discipline) THEN RAISE EXCEPTION 'NOTIFICATION_PERMISSION_REVOKED' USING ERRCODE='42501'; END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.state<>'DRAFT' THEN RAISE EXCEPTION 'NOTIFICATION_CREATE_DRAFT_ONLY' USING ERRCODE='23514'; END IF;
  NEW.entry_setting_id:=sid; NEW.created_at:=CURRENT_TIMESTAMP;
 ELSE
  NEW.issue_setting_id:=sid; NEW.issued_at:=CURRENT_TIMESTAMP;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['inspection_notification_settings', 'inspection_itp_stages', 'inspection_notifications'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            $scope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id={$table}.project_id AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
            $read = match ($table) {
                'inspection_notification_settings' => $scope,
                'inspection_itp_stages' => "{$scope} AND EXISTS(SELECT 1 FROM document_revisions r WHERE r.id=revision_id)",
                default => "{$scope} AND (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid OR (state='ISSUED' AND jsonb_exists(recipient_company_ids,current_setting('epcc.company_id',true))))",
            };
            DB::statement("CREATE POLICY notification_read ON {$table} FOR SELECT USING ({$read})");
            DB::statement("CREATE POLICY notification_insert ON {$table} FOR INSERT WITH CHECK ({$scope} AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)");
            if ($table === 'inspection_notifications') {
                DB::statement("CREATE POLICY notification_issue ON {$table} FOR UPDATE USING ({$scope} AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid) WITH CHECK ({$scope} AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)");
            }
            DB::statement("CREATE TRIGGER notification_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION epcc_guard_notification()");
        }
    }

    public function down(): void
    {
        throw new LogicException('Forward-only issued notification evidence.');
    }
};
