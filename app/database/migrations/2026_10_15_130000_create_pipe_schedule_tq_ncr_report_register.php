<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** First provenance-aware TQ/NCR register, scoped to issued pipe-schedule discrepancies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipe_schedule_discrepancy_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('request_id');
            $t->uuid('project_id');
            $t->uuid('company_id'); // recording tenant; not necessarily responsible company
            $t->string('kind', 3); // TQ / NCR
            $t->string('origin', 24); // IN_SYSTEM / EXTERNAL_REGISTER
            $t->string('state', 24); // DRAFT / EXTERNAL_RECORDED (neither means approved)
            $t->string('report_number', 160)->nullable();
            $t->string('report_revision', 64)->nullable();
            $t->string('reported_issuer', 200)->nullable();
            $t->string('reported_signer', 200)->nullable();
            $t->string('reported_signer_capacity', 200)->nullable();
            $t->timestampTz('reported_issued_at')->nullable();
            $t->timestampTz('reported_signed_at')->nullable();
            $t->string('external_register_ref', 512)->nullable();
            $t->uuid('recorded_by'); // issuer for neither mode: this is only the recorder
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('request_id')->references('id')->on('pipe_schedule_exception_requests')->restrictOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['project_id', 'request_id', 'kind'], 'pipe_reports_request_kind_idx');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_discrepancy_reports ADD CONSTRAINT pipe_reports_provenance CHECK (
                kind IN ('TQ', 'NCR') AND (
                    (origin = 'IN_SYSTEM' AND state = 'DRAFT'
                     AND report_number IS NULL AND report_revision IS NULL
                     AND reported_issuer IS NULL AND reported_signer IS NULL
                     AND reported_signer_capacity IS NULL AND reported_issued_at IS NULL
                     AND reported_signed_at IS NULL AND external_register_ref IS NULL)
                    OR
                    (origin = 'EXTERNAL_REGISTER' AND state = 'EXTERNAL_RECORDED'
                     AND report_number IS NOT NULL AND reported_issuer IS NOT NULL
                     AND reported_signer IS NOT NULL AND reported_signer_capacity IS NOT NULL
                     AND external_register_ref IS NOT NULL
                     AND length(btrim(report_number)) >= 2
                     AND length(btrim(reported_issuer)) >= 2
                     AND length(btrim(reported_signer)) >= 2
                     AND length(btrim(reported_signer_capacity)) >= 2
                     AND reported_issued_at IS NOT NULL AND reported_signed_at IS NOT NULL
                     AND length(btrim(external_register_ref)) >= 2
                     AND (report_revision IS NULL OR length(btrim(report_revision)) >= 1))
                )
            )
        SQL);
        DB::unprepared(<<<'SQL'
            -- migrate:fresh drops tables, not standalone functions. Reinstall the same guard.
            CREATE OR REPLACE FUNCTION epcc_pipe_discrepancy_report_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'PIPE_REPORT_APPEND_ONLY' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pipe_schedule_exception_requests r
                    JOIN users u ON u.id = NEW.recorded_by
                    WHERE r.id = NEW.request_id AND r.project_id = NEW.project_id
                      AND r.company_id = NEW.company_id
                      AND u.company_id = NEW.company_id AND u.is_active = true
                ) THEN
                    RAISE EXCEPTION 'PIPE_REPORT_SOURCE_INVALID' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER pipe_reports_guard BEFORE INSERT OR UPDATE OR DELETE
                ON pipe_schedule_discrepancy_reports FOR EACH ROW EXECUTE FUNCTION epcc_pipe_discrepancy_report_guard();
        SQL);
        DB::statement('ALTER TABLE pipe_schedule_discrepancy_reports ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pipe_schedule_discrepancy_reports FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY pipe_reports_tenant ON pipe_schedule_discrepancy_reports
            USING (project_id IN (
                SELECT pc.project_id FROM project_company pc
                WHERE pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                  AND (NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                       OR pc.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
            ))
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND project_id IN (
                    SELECT pc.project_id FROM project_company pc
                    WHERE pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                      AND (NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                           OR pc.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
                )
                AND epcc_project_is_writable(project_id)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pipe_schedule_discrepancy_reports');
        DB::statement('DROP FUNCTION IF EXISTS epcc_pipe_discrepancy_report_guard()');
    }
};
