<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** NEW acceptance provenance only. Never an effective-scope selection/backfill. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE accepted_mto_batches ADD CONSTRAINT mto_batch_source_tuple UNIQUE (project_id, company_id, id, mto_document_id, mto_revision_id)');
        // Historical anomalies are NOT silently repaired/declared validated.
        // PostgreSQL enforces this for new rows; legacy VALIDATE is a separate gate.
        DB::statement('ALTER TABLE accepted_mto_rows ADD CONSTRAINT mto_row_source_tuple FOREIGN KEY (project_id, company_id, accepted_mto_batch_id, mto_document_id, mto_revision_id) REFERENCES accepted_mto_batches (project_id, company_id, id, mto_document_id, mto_revision_id) NOT VALID');

        Schema::create('mto_acceptance_clocks', function (Blueprint $t): void {
            $t->uuid('project_id')->primary();
            $t->bigInteger('last_ordinal');
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
        });
        Schema::create('mto_acceptance_journal', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('accepted_mto_batch_id')->unique();
            $t->uuid('mto_document_id');
            $t->uuid('mto_revision_id')->unique();
            $t->bigInteger('ordinal');
            $t->string('origin', 24)->default('NEW');
            $t->string('selection_state', 32)->default('UNRESOLVED');
            $t->timestampTz('recorded_at')->useCurrent();
            $t->unique(['project_id', 'ordinal']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE mto_acceptance_clocks ADD CHECK(last_ordinal > 0);
            ALTER TABLE mto_acceptance_journal ADD CHECK(ordinal > 0 AND origin='NEW' AND selection_state='UNRESOLVED');
            ALTER TABLE mto_acceptance_journal ADD CONSTRAINT mto_journal_source_tuple
              FOREIGN KEY(project_id,company_id,accepted_mto_batch_id,mto_document_id,mto_revision_id)
              REFERENCES accepted_mto_batches(project_id,company_id,id,mto_document_id,mto_revision_id);

            CREATE OR REPLACE FUNCTION epcc_mto_clock_step() RETURNS trigger
            LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION 'MTO clock cannot be deleted' USING ERRCODE='55000'; END IF;
              IF TG_OP='INSERT' THEN
                IF NEW.last_ordinal<>1 THEN RAISE EXCEPTION 'MTO clock starts at one' USING ERRCODE='23514'; END IF;
              ELSIF NEW.project_id<>OLD.project_id OR NEW.last_ordinal<>OLD.last_ordinal+1 THEN
                RAISE EXCEPTION 'MTO clock must advance by one' USING ERRCODE='23514';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER mto_clock_step BEFORE INSERT OR UPDATE OR DELETE ON mto_acceptance_clocks
              FOR EACH ROW EXECUTE FUNCTION epcc_mto_clock_step();

            CREATE OR REPLACE FUNCTION epcc_mto_journal_insert_path() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              -- Integrity guard for this automatic trigger path, NOT an authority policy.
              IF pg_trigger_depth()<>2 THEN
                RAISE EXCEPTION 'MTO journal is generated only with a new snapshot' USING ERRCODE='55000';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER mto_journal_insert_path BEFORE INSERT ON mto_acceptance_journal
              FOR EACH ROW EXECUTE FUNCTION epcc_mto_journal_insert_path();
            CREATE TRIGGER mto_journal_immutable BEFORE UPDATE OR DELETE ON mto_acceptance_journal
              FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();

            CREATE OR REPLACE FUNCTION epcc_record_new_mto_acceptance() RETURNS trigger
            LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
            DECLARE active boolean; n bigint;
            BEGIN
              SELECT state='ACTIVE' INTO active FROM projects WHERE id=NEW.project_id FOR UPDATE;
              IF NOT FOUND OR NOT active THEN RAISE EXCEPTION 'MTO project is not writable/visible' USING ERRCODE='55000'; END IF;
              IF NOT EXISTS(SELECT 1 FROM mto_revisions r JOIN mto_documents d ON d.id=r.mto_document_id
                WHERE r.id=NEW.mto_revision_id AND r.project_id=NEW.project_id
                AND r.company_id=NEW.company_id AND r.mto_document_id=NEW.mto_document_id
                AND d.project_id=NEW.project_id) THEN
                RAISE EXCEPTION 'MTO source tuple mismatch' USING ERRCODE='23503';
              END IF;
              INSERT INTO mto_acceptance_clocks(project_id,last_ordinal) VALUES(NEW.project_id,1)
                ON CONFLICT(project_id) DO UPDATE SET last_ordinal=mto_acceptance_clocks.last_ordinal+1
                RETURNING last_ordinal INTO n;
              INSERT INTO mto_acceptance_journal(id,project_id,company_id,accepted_mto_batch_id,mto_document_id,mto_revision_id,ordinal)
                VALUES(gen_random_uuid(),NEW.project_id,NEW.company_id,NEW.id,NEW.mto_document_id,NEW.mto_revision_id,n);
              RETURN NEW;
            END; $$;
            CREATE TRIGGER mto_record_new_acceptance AFTER INSERT ON accepted_mto_batches
              FOR EACH ROW EXECUTE FUNCTION epcc_record_new_mto_acceptance();

            CREATE OR REPLACE FUNCTION epcc_check_new_mto_acceptance_complete() RETURNS trigger
            LANGUAGE plpgsql SET search_path=public,pg_catalog AS $$
            DECLARE prior_company text; prior_project text; complete boolean;
            BEGIN
              -- Deferred triggers can run after nested TenantContext restores its
              -- caller. Pin only this already-inserted source tuple while checking
              -- integrity, then restore both GUCs; no RLS bypass/definer rights.
              prior_company:=current_setting('epcc.company_id',true);
              prior_project:=current_setting('epcc.project_id',true);
              PERFORM set_config('epcc.company_id',NEW.company_id::text,true);
              PERFORM set_config('epcc.project_id',NEW.project_id::text,true);
              SELECT EXISTS(SELECT 1 FROM mto_acceptance_journal j WHERE j.accepted_mto_batch_id=NEW.id)
                AND EXISTS(SELECT 1 FROM accepted_mto_rows r WHERE r.accepted_mto_batch_id=NEW.id)
                AND EXISTS(SELECT 1 FROM mto_revisions r WHERE r.id=NEW.mto_revision_id
                  AND r.project_id=NEW.project_id AND r.company_id=NEW.company_id
                  AND r.mto_document_id=NEW.mto_document_id AND r.state='ACCEPTED') INTO complete;
              PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
              PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
              IF NOT complete THEN RAISE EXCEPTION 'Incomplete NEW MTO acceptance' USING ERRCODE='23514'; END IF;
              RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER mto_new_acceptance_complete AFTER INSERT ON accepted_mto_batches
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION epcc_check_new_mto_acceptance_complete();
        SQL);
        foreach (['mto_acceptance_clocks', 'mto_acceptance_journal'] as $table) {
            $companyCheck = $table === 'mto_acceptance_journal'
                ? "AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid" : '';
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY mto_tenant ON {$table} USING (
                  project_id IN (SELECT pc.project_id FROM project_company pc
                    WHERE pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
                    AND (NULLIF(current_setting('epcc.project_id',true),'') IS NULL
                      OR pc.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid))
                ) WITH CHECK (
                  project_id IN (SELECT pc.project_id FROM project_company pc
                    WHERE pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
                    AND (NULLIF(current_setting('epcc.project_id',true),'') IS NULL
                      OR pc.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid))
                  {$companyCheck}
                );
                CREATE POLICY mto_archive_write ON {$table} AS RESTRICTIVE FOR ALL
                  USING(true) WITH CHECK(epcc_project_is_writable(project_id));
                CREATE POLICY mto_archive_delete ON {$table} AS RESTRICTIVE FOR DELETE
                  USING(epcc_project_is_writable(project_id));
            SQL);
        }
        DB::unprepared(<<<'SQL'
            CREATE POLICY mto_journal_no_update ON mto_acceptance_journal AS RESTRICTIVE FOR UPDATE USING(false) WITH CHECK(false);
            CREATE POLICY mto_journal_no_delete ON mto_acceptance_journal AS RESTRICTIVE FOR DELETE USING(false);
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Acceptance chronology is forward-only; preserve provenance and use an audited forward migration.');
    }
};
