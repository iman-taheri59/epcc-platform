<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A historical DB observation, explicitly NOT authoritative scope admission. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mto_scope_observations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('accepted_mto_batch_id')->unique();
            $t->uuid('mto_document_id');
            $t->uuid('mto_revision_id');
            $t->string('origin', 32)->default('DATABASE_OBSERVATION');
            $t->string('admission_state', 24)->default('UNADMITTED');
            $t->jsonb('payload');
            $t->char('payload_hash', 64);
            $t->char('source_rows_hash', 64);
            $t->timestampTz('observed_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE mto_scope_observations ADD CONSTRAINT mto_observation_source_tuple
              FOREIGN KEY(project_id,company_id,accepted_mto_batch_id,mto_document_id,mto_revision_id)
              REFERENCES accepted_mto_batches(project_id,company_id,id,mto_document_id,mto_revision_id);
            ALTER TABLE mto_scope_observations ADD CONSTRAINT mto_observation_new_chronology
              FOREIGN KEY(accepted_mto_batch_id) REFERENCES mto_acceptance_journal(accepted_mto_batch_id);
            ALTER TABLE mto_scope_observations ADD CHECK(origin='DATABASE_OBSERVATION' AND admission_state='UNADMITTED'
              AND jsonb_typeof(payload)='object' AND payload_hash ~ '^[a-f0-9]{64}$' AND source_rows_hash ~ '^[a-f0-9]{64}$');

            CREATE OR REPLACE FUNCTION epcc_mto_observed_source_rows(p_batch uuid,p_project uuid)
            RETURNS jsonb LANGUAGE sql STABLE SET search_path=public,pg_catalog AS $$
              SELECT COALESCE(jsonb_agg(jsonb_build_object(
                'id',id,'source_staging_row_id',source_staging_row_id,'source_row_hash',source_row_hash,
                'material_item_id',material_item_id,'quantity',gross_quantity::text,'canonical_quantity',canonical_quantity::text,'unit',unit,
                'scope_id',scope_id,'hierarchy_revision_id',hierarchy_revision_id
              ) ORDER BY id),'[]'::jsonb) FROM accepted_mto_rows
              WHERE accepted_mto_batch_id=p_batch AND project_id=p_project
            $$;
            CREATE OR REPLACE FUNCTION epcc_mto_observation_insert_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
            BEGIN
              IF pg_trigger_depth()<>2 THEN
                RAISE EXCEPTION 'Scope observation is generated only by NEW acceptance capture' USING ERRCODE='55000';
              END IF;
              NEW.payload_hash:=encode(sha256(convert_to(NEW.payload::text,'UTF8')),'hex');
              NEW.source_rows_hash:=encode(sha256(convert_to((NEW.payload->'rows')::text,'UTF8')),'hex');
              RETURN NEW;
            END; $$;
            CREATE TRIGGER mto_observation_insert_guard BEFORE INSERT ON mto_scope_observations
              FOR EACH ROW EXECUTE FUNCTION epcc_mto_observation_insert_guard();
            CREATE TRIGGER mto_observation_immutable BEFORE UPDATE OR DELETE ON mto_scope_observations
              FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();

            CREATE OR REPLACE FUNCTION epcc_capture_new_mto_scope_observation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
            DECLARE prior_company text; prior_project text; captured jsonb;
            BEGIN
              -- This is observation of an already authorized INSERT, not a generic
              -- tenant lookup or admission endpoint. All functions are invoker-only.
              prior_company:=current_setting('epcc.company_id',true);
              prior_project:=current_setting('epcc.project_id',true);
              PERFORM set_config('epcc.company_id',NEW.company_id::text,true);
              PERFORM set_config('epcc.project_id',NEW.project_id::text,true);
              WITH source_rows AS (
                SELECT epcc_mto_observed_source_rows(NEW.id,NEW.project_id) AS rows
              ), leaves AS (
                SELECT s.id,s.project_id,s.facility_id,s.area_id FROM subsystems s
                WHERE s.project_id=NEW.project_id AND s.id IN (
                  SELECT scope_id FROM accepted_mto_rows WHERE accepted_mto_batch_id=NEW.id AND project_id=NEW.project_id)
              ), physical_areas AS (
                SELECT a.id,a.project_id,a.facility_id FROM areas a WHERE a.project_id=NEW.project_id
                  AND a.id IN (SELECT area_id FROM leaves)
              ), physical_facilities AS (
                SELECT f.id,f.project_id FROM facilities f WHERE f.project_id=NEW.project_id
                  AND f.id IN (SELECT facility_id FROM leaves UNION SELECT facility_id FROM physical_areas)
              )
              SELECT jsonb_build_object(
                'schema_version',1,'capture_kind','DATABASE_OBSERVATION',
                'batch_id',NEW.id,'project_id',NEW.project_id,
                'scope_mode',NEW.scope_mode,'header_scope_id',NEW.header_scope_id,
                'claimed_hierarchy_revision_id',NEW.hierarchy_revision_id,
                'rows',(SELECT rows FROM source_rows),
                'subsystems',COALESCE((SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM leaves l),'[]'::jsonb),
                'areas',COALESCE((SELECT jsonb_agg(to_jsonb(a) ORDER BY a.id) FROM physical_areas a),'[]'::jsonb),
                'facilities',COALESCE((SELECT jsonb_agg(to_jsonb(f) ORDER BY f.id) FROM physical_facilities f),'[]'::jsonb)
              ) INTO captured;
              INSERT INTO mto_scope_observations(id,project_id,company_id,accepted_mto_batch_id,mto_document_id,mto_revision_id,payload,payload_hash,source_rows_hash,observed_at)
                VALUES(gen_random_uuid(),NEW.project_id,NEW.company_id,NEW.id,NEW.mto_document_id,NEW.mto_revision_id,captured,'','',clock_timestamp());
              PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
              PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
              RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER mto_scope_observation_capture AFTER INSERT ON accepted_mto_batches
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION epcc_capture_new_mto_scope_observation();

            ALTER TABLE mto_scope_observations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE mto_scope_observations FORCE ROW LEVEL SECURITY;
            CREATE POLICY mto_observation_tenant ON mto_scope_observations USING (
              project_id IN (SELECT pc.project_id FROM project_company pc
                WHERE pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
                AND (NULLIF(current_setting('epcc.project_id',true),'') IS NULL
                  OR pc.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid))
            ) WITH CHECK (
              company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
              AND project_id IN (SELECT pc.project_id FROM project_company pc
                WHERE pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
                AND (NULLIF(current_setting('epcc.project_id',true),'') IS NULL
                  OR pc.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid))
            );
            CREATE POLICY mto_observation_archive ON mto_scope_observations AS RESTRICTIVE FOR ALL
              USING(true) WITH CHECK(epcc_project_is_writable(project_id));
            CREATE POLICY mto_observation_no_update ON mto_scope_observations AS RESTRICTIVE FOR UPDATE USING(false) WITH CHECK(false);
            CREATE POLICY mto_observation_no_delete ON mto_scope_observations AS RESTRICTIVE FOR DELETE USING(false);
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Scope observations preserve provenance; use an audited forward migration.');
    }
};
