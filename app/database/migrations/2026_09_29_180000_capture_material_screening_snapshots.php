<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // NULL for historical runs. Do not invent evidence from today's rules/data.
        Schema::table('fitting_review_runs',function (Blueprint $t) {
            $t->uuid('screening_run_id')->nullable();
            $t->unique(['id','project_id','company_id','definition_id','created_by'],'screening_run_scope_unique');
        });
        Schema::create('material_screening_snapshots',function (Blueprint $t) {
            $t->uuid('run_id')->primary();
            $t->uuid('project_id');$t->uuid('company_id');$t->uuid('definition_id');$t->uuid('created_by');
            $t->timestampTz('created_at');
            // Preserve serialized bytes for reproducible hashes; jsonb would reorder object keys.
            $t->text('input_json');$t->text('result_json');
            $t->string('result_sha256',64);$t->string('implementation_sha256',64);
            $t->foreign(['run_id','project_id','company_id','definition_id','created_by'],'screening_snapshot_scope_fk')
                ->references(['id','project_id','company_id','definition_id','created_by'])->on('fitting_review_runs')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE fitting_review_runs ADD CONSTRAINT screening_run_self CHECK (screening_run_id IS NULL OR screening_run_id=id);
            ALTER TABLE fitting_review_runs ADD CONSTRAINT screening_run_evidence_fk FOREIGN KEY(screening_run_id)
                REFERENCES material_screening_snapshots(run_id) DEFERRABLE INITIALLY DEFERRED;
            ALTER TABLE material_screening_snapshots ADD CONSTRAINT screening_snapshot_shape CHECK (COALESCE(
                jsonb_typeof(input_json::jsonb)='object' AND jsonb_typeof(result_json::jsonb)='object'
                AND length(result_json::jsonb->>'ruleset')>0
                AND (result_json::jsonb->>'status') IN ('DISCREPANCY','INCOMPLETE','REVIEW_REQUIRED')
                AND result_json::jsonb->'engineering_approved'='false'::jsonb
                AND result_json::jsonb->'automatic_consumption_allowed'='false'::jsonb
                AND jsonb_typeof(result_json::jsonb->'findings')='array'
                AND jsonb_typeof(result_json::jsonb->'references')='array'
                AND result_json::jsonb->>'input_sha256'=encode(sha256(convert_to(input_json,'UTF8')),'hex')
                AND result_sha256=encode(sha256(convert_to(result_json,'UTF8')),'hex')
                AND implementation_sha256 ~ '^[0-9a-f]{64}$',false));
            CREATE OR REPLACE FUNCTION epcc_stamp_screening_run() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                SELECT CASE WHEN d.material_family<>'FITTING' THEN NEW.id ELSE NULL END INTO NEW.screening_run_id
                    FROM project_fitting_definitions d WHERE d.id=NEW.definition_id AND d.project_id=NEW.project_id;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER screening_run_stamp BEFORE INSERT ON fitting_review_runs
                FOR EACH ROW EXECUTE FUNCTION epcc_stamp_screening_run();
            ALTER TABLE material_screening_snapshots ENABLE ROW LEVEL SECURITY;
            ALTER TABLE material_screening_snapshots FORCE ROW LEVEL SECURITY;
        SQL);
        $visible="(material_screening_snapshots.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=material_screening_snapshots.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))";
        DB::statement("CREATE POLICY material_screening_read ON material_screening_snapshots FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY material_screening_insert ON material_screening_snapshots FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM users u JOIN project_memberships pm ON pm.user_id=u.id
                WHERE u.id=material_screening_snapshots.created_by AND u.company_id=material_screening_snapshots.company_id
                    AND u.is_active AND pm.project_id=material_screening_snapshots.project_id)
            AND EXISTS (SELECT 1 FROM fitting_review_runs r JOIN project_fitting_definitions d ON d.id=r.definition_id
                WHERE r.id=material_screening_snapshots.run_id AND r.screening_run_id=r.id
                    AND material_screening_snapshots.input_json::jsonb=jsonb_build_object(
                        'component_type',d.component_type,'qualifier',d.qualifier,'size_designation',d.size_designation,
                        'reference_standard',d.reference_standard,'material_spec',d.material_spec,'material_grade',d.material_grade,
                        'declaration_reason',d.declaration_reason,'spec_number',d.spec_number,'spec_revision',d.spec_revision,
                        'spec_clause',d.spec_clause,'source_text',d.source_text,
                        'properties',CASE WHEN d.properties='{}'::jsonb THEN '[]'::jsonb ELSE d.properties END)))");
        // No UPDATE or DELETE policies. Native deferred FK enforces atomic evidence even after tenant GUC restoration.
    }
    public function down(): void
    {
        throw new RuntimeException('Screening evidence is forward-only; restore an explicitly selected backup to revert.');
    }
};
