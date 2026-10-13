<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('material_screening_snapshots',fn(Blueprint $t)=>$t->unique(['run_id','project_id'],'screening_snapshot_project_unique'));
        Schema::create('material_finding_responses',function(Blueprint $t){
            $t->uuid('id')->primary();$t->uuid('run_id');$t->uuid('project_id');
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');$t->string('actor_name');
            $t->string('actor_kind',16);$t->integer('review_stage')->nullable();
            $t->integer('finding_index');$t->string('finding_key',64);$t->string('finding_code',100);$t->string('finding_field',200);
            $t->integer('sequence');$t->uuid('request_key');$t->string('kind',24);
            $t->text('message');$t->string('spec_number',120);$t->string('spec_revision',60);$t->string('spec_clause',120);$t->text('source_quote')->nullable();
            $t->foreign(['run_id','project_id'],'finding_response_snapshot_fk')->references(['run_id','project_id'])->on('material_screening_snapshots')->restrictOnDelete();
            $t->unique(['run_id','finding_index','sequence'],'finding_response_sequence_unique');
            $t->unique(['project_id','created_by','request_key'],'finding_response_request_unique');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE material_finding_responses ADD CONSTRAINT finding_response_shape CHECK (
                finding_index>=0 AND sequence>0 AND finding_key ~ '^[0-9a-f]{64}$'
                AND kind IN ('EXPLANATION','NA_REQUEST','REVIEW_NOTE')
                AND ((actor_kind='AUTHOR' AND kind IN ('EXPLANATION','NA_REQUEST') AND review_stage IS NULL)
                    OR (actor_kind='REVIEWER' AND kind='REVIEW_NOTE' AND review_stage BETWEEN 1 AND 5 AND review_stage IS NOT NULL))
                AND length(trim(message))>0 AND length(message)<=4000
                AND length(trim(spec_number))>0 AND length(trim(spec_revision))>0 AND length(trim(spec_clause))>0
                AND (source_quote IS NULL OR length(source_quote)<=4000)
                AND (kind<>'NA_REQUEST' OR (source_quote IS NOT NULL AND length(trim(source_quote))>0))
            );
            ALTER TABLE material_finding_responses ENABLE ROW LEVEL SECURITY;
            ALTER TABLE material_finding_responses FORCE ROW LEVEL SECURITY;
            CREATE OR REPLACE FUNCTION epcc_guard_material_finding_response() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE d record; steps jsonb; finding jsonb; result_hash text; roles jsonb; scope jsonb;
                signer_name text; already integer; returned boolean; selector text; reviewer boolean;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('fitting-review:'||NEW.project_id::text,0));
                SELECT def.* INTO d FROM material_screening_snapshots s
                    JOIN project_fitting_definitions def ON def.id=s.definition_id
                    WHERE s.run_id=NEW.run_id AND s.project_id=NEW.project_id;
                IF NOT FOUND THEN RAISE EXCEPTION 'Captured definition unavailable' USING ERRCODE='42501'; END IF;
                SELECT s.result_json::jsonb->'findings'->NEW.finding_index,s.result_sha256 INTO finding,result_hash
                    FROM material_screening_snapshots s WHERE s.run_id=NEW.run_id;
                IF NEW.finding_index<0 OR finding IS NULL
                    OR NEW.finding_key<>encode(sha256(convert_to('material-finding-v1:'||result_hash||':'||NEW.finding_index::text,'UTF8')),'hex')
                    OR NEW.finding_code IS DISTINCT FROM finding->>'code'
                    OR NEW.finding_field IS DISTINCT FROM finding->>'field'
                THEN RAISE EXCEPTION 'Finding binding mismatch' USING ERRCODE='42501'; END IF;
                SELECT pm.role_codes,pm.discipline_scope,u.name INTO roles,scope,signer_name
                    FROM users u JOIN project_memberships pm ON pm.user_id=u.id
                    WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id;
                IF NOT FOUND OR (scope IS NOT NULL AND NOT scope ? 'PIPING') THEN
                    RAISE EXCEPTION 'Actor inactive or outside discipline' USING ERRCODE='42501'; END IF;
                SELECT p.steps INTO steps FROM fitting_review_policies p JOIN fitting_review_runs r ON r.policy_id=p.id
                    WHERE r.id=NEW.run_id AND r.project_id=NEW.project_id;
                SELECT count(*),COALESCE(bool_or(decision='RETURN'),false) INTO already,returned
                    FROM fitting_review_decisions WHERE run_id=NEW.run_id;
                IF EXISTS (SELECT 1 FROM project_fitting_definitions WHERE revises_id=d.id)
                    OR (NOT returned AND already>=jsonb_array_length(steps)) THEN
                    RAISE EXCEPTION 'Definition closed or superseded' USING ERRCODE='42501'; END IF;
                selector:=steps->already->>'selector';
                reviewer:=NOT returned AND already<jsonb_array_length(steps)
                    AND COALESCE(selector='user:'||NEW.created_by::text
                        OR (split_part(selector,':',1)='role' AND split_part(selector,':',2)=NEW.company_id::text AND roles ? split_part(selector,':',3)),false)
                    AND NOT EXISTS (SELECT 1 FROM fitting_review_decisions WHERE run_id=NEW.run_id AND created_by=NEW.created_by)
                    AND NOT EXISTS (SELECT 1 FROM project_fitting_definitions def
                        WHERE (def.id=COALESCE(d.root_id,d.id) OR def.root_id=COALESCE(d.root_id,d.id)) AND def.created_by=NEW.created_by);
                IF NEW.kind='REVIEW_NOTE' THEN
                    IF NOT reviewer OR NEW.actor_kind<>'REVIEWER' OR NEW.review_stage IS DISTINCT FROM already+1 THEN
                        RAISE EXCEPTION 'Not the independent current reviewer' USING ERRCODE='42501'; END IF;
                ELSE
                    IF reviewer OR NEW.company_id<>d.company_id OR NEW.actor_kind<>'AUTHOR' OR NEW.review_stage IS NOT NULL THEN
                        RAISE EXCEPTION 'Not the author team response role' USING ERRCODE='42501'; END IF;
                END IF;
                SELECT COALESCE(max(sequence),0)+1 INTO NEW.sequence FROM material_finding_responses
                    WHERE run_id=NEW.run_id AND finding_index=NEW.finding_index;
                NEW.actor_name:=signer_name;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER material_finding_response_guard BEFORE INSERT ON material_finding_responses
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_material_finding_response();
        SQL);
        $visible="(material_finding_responses.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=material_finding_responses.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))";
        DB::statement("CREATE POLICY finding_response_read ON material_finding_responses FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY finding_response_insert ON material_finding_responses FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM users u JOIN project_memberships pm ON pm.user_id=u.id
                WHERE u.id=material_finding_responses.created_by AND u.company_id=material_finding_responses.company_id
                    AND u.is_active AND pm.project_id=material_finding_responses.project_id))");
        // Append-only. Notes are not resolutions, decisions, exceptions or approval state.
    }
    public function down(): void {throw new RuntimeException('Finding response history is forward-only; restore an explicitly selected backup.');}
};
