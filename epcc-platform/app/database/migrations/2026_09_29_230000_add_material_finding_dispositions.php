<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('material_finding_responses',fn(Blueprint $t)=>$t->unique(['id','run_id','project_id'],'finding_response_scope_unique'));
        Schema::create('material_finding_dispositions',function(Blueprint $t){
            $t->uuid('id')->primary();$t->uuid('response_id');$t->uuid('run_id');$t->uuid('project_id');
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');$t->string('actor_name');$t->integer('review_stage');
            $t->string('finding_key',64);$t->integer('sequence');$t->uuid('request_key');
            $t->string('kind',24);$t->string('effect',24)->default('RECORD_ONLY');
            $t->text('rationale');$t->string('spec_number',120);$t->string('spec_revision',60);$t->string('spec_clause',120);$t->text('source_quote')->nullable();
            $t->uuid('supersedes_id')->nullable()->unique();$t->text('change_reason')->nullable();
            $t->foreign(['response_id','run_id','project_id'],'finding_disposition_response_fk')->references(['id','run_id','project_id'])->on('material_finding_responses')->restrictOnDelete();
            $t->unique(['id','response_id','project_id'],'finding_disposition_scope_unique');
            $t->foreign(['supersedes_id','response_id','project_id'],'finding_disposition_previous_fk')->references(['id','response_id','project_id'])->on('material_finding_dispositions')->restrictOnDelete();
            $t->unique(['response_id','sequence'],'finding_disposition_sequence_unique');
            $t->unique(['project_id','created_by','request_key'],'finding_disposition_request_unique');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE material_finding_dispositions ADD CONSTRAINT finding_disposition_shape CHECK (
                sequence>0 AND review_stage BETWEEN 1 AND 5 AND finding_key ~ '^[0-9a-f]{64}$' AND effect='RECORD_ONLY'
                AND kind IN ('ACCEPT_EXPLANATION','REQUEST_EVIDENCE','REJECT_NA','ACCEPT_NA')
                AND length(trim(rationale))>0 AND length(rationale)<=4000
                AND length(trim(spec_number))>0 AND length(trim(spec_revision))>0 AND length(trim(spec_clause))>0
                AND (source_quote IS NULL OR length(source_quote)<=4000)
                AND (kind<>'ACCEPT_NA' OR (source_quote IS NOT NULL AND length(trim(source_quote))>0))
                AND ((supersedes_id IS NULL AND change_reason IS NULL) OR (supersedes_id IS NOT NULL
                    AND change_reason IS NOT NULL AND length(trim(change_reason))>0 AND length(change_reason)<=4000))
            );
            ALTER TABLE material_finding_dispositions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE material_finding_dispositions FORCE ROW LEVEL SECURITY;
            CREATE OR REPLACE FUNCTION epcc_guard_material_finding_disposition() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE n record; d record; prev record; steps jsonb; roles jsonb; scope jsonb; signer_name text;
                already integer; returned boolean; selector text;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('fitting-review:'||NEW.project_id::text,0));
                SELECT r.*,s.definition_id INTO n FROM material_finding_responses r
                    JOIN material_screening_snapshots s ON s.run_id=r.run_id
                    WHERE r.id=NEW.response_id AND r.run_id=NEW.run_id AND r.project_id=NEW.project_id;
                IF NOT FOUND THEN RAISE EXCEPTION 'Response unavailable' USING ERRCODE='42501'; END IF;
                IF NEW.created_by=n.created_by OR NEW.finding_key IS DISTINCT FROM n.finding_key
                    OR NOT ((n.kind='EXPLANATION' AND NEW.kind IN ('ACCEPT_EXPLANATION','REQUEST_EVIDENCE'))
                        OR (n.kind='NA_REQUEST' AND NEW.kind IN ('ACCEPT_NA','REJECT_NA','REQUEST_EVIDENCE'))) THEN
                    RAISE EXCEPTION 'Response binding, kind or self-disposition violation' USING ERRCODE='42501'; END IF;
                SELECT * INTO d FROM project_fitting_definitions WHERE id=n.definition_id AND project_id=NEW.project_id;
                SELECT pm.role_codes,pm.discipline_scope,u.name INTO roles,scope,signer_name
                    FROM users u JOIN project_memberships pm ON pm.user_id=u.id
                    WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id;
                IF NOT FOUND OR (scope IS NOT NULL AND NOT scope ? 'PIPING') THEN
                    RAISE EXCEPTION 'Reviewer inactive or outside discipline' USING ERRCODE='42501'; END IF;
                SELECT p.steps INTO steps FROM fitting_review_policies p JOIN fitting_review_runs r ON r.policy_id=p.id
                    WHERE r.id=NEW.run_id AND r.project_id=NEW.project_id;
                SELECT count(*),COALESCE(bool_or(decision='RETURN'),false) INTO already,returned
                    FROM fitting_review_decisions WHERE run_id=NEW.run_id;
                selector:=steps->already->>'selector';
                IF returned OR already>=jsonb_array_length(steps) OR NEW.review_stage IS DISTINCT FROM already+1
                    OR EXISTS (SELECT 1 FROM project_fitting_definitions WHERE revises_id=d.id)
                    OR EXISTS (SELECT 1 FROM fitting_review_decisions WHERE run_id=NEW.run_id AND created_by=NEW.created_by)
                    OR EXISTS (SELECT 1 FROM project_fitting_definitions def WHERE
                        (def.id=COALESCE(d.root_id,d.id) OR def.root_id=COALESCE(d.root_id,d.id)) AND def.created_by=NEW.created_by)
                    OR NOT COALESCE(selector='user:'||NEW.created_by::text OR
                        (split_part(selector,':',1)='role' AND split_part(selector,':',2)=NEW.company_id::text AND roles ? split_part(selector,':',3)),false)
                THEN RAISE EXCEPTION 'Not the independent current reviewer' USING ERRCODE='42501'; END IF;
                SELECT * INTO prev FROM material_finding_dispositions WHERE response_id=NEW.response_id ORDER BY sequence DESC LIMIT 1;
                IF (FOUND AND NEW.supersedes_id IS DISTINCT FROM prev.id) OR (NOT FOUND AND NEW.supersedes_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'Stale disposition predecessor' USING ERRCODE='23514'; END IF;
                NEW.sequence:=COALESCE(prev.sequence,0)+1;NEW.actor_name:=signer_name;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER material_finding_disposition_guard BEFORE INSERT ON material_finding_dispositions
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_material_finding_disposition();
        SQL);
        $visible="(material_finding_dispositions.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=material_finding_dispositions.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))";
        DB::statement("CREATE POLICY finding_disposition_read ON material_finding_dispositions FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY finding_disposition_insert ON material_finding_dispositions FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM users u JOIN project_memberships pm ON pm.user_id=u.id
                WHERE u.id=material_finding_dispositions.created_by AND u.company_id=material_finding_dispositions.company_id
                    AND u.is_active AND pm.project_id=material_finding_dispositions.project_id))");
    }
    public function down(): void {throw new RuntimeException('Formal disposition history is forward-only; restore an explicitly selected backup.');}
};
