<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Candidate offline evidence, NOT a disposition or an approved exception. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipe_schedule_external_signatures', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('request_id');
            $t->uuid('project_id');
            $t->uuid('company_id'); // uploader's RLS tenant, not the signer
            $t->uuid('signer_company_id'); // claimed responsible party; verified later
            $t->string('signer_name', 200);
            $t->string('signer_capacity', 200);
            $t->string('document_number', 160);
            $t->string('document_revision', 64);
            $t->string('file_sha256', 64);
            $t->timestampTz('signed_at');
            $t->uuid('uploaded_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('request_id')->references('id')->on('pipe_schedule_exception_requests')->restrictOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('signer_company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['project_id', 'request_id'], 'pipe_ext_signatures_request_idx');
        });
        Schema::create('pipe_schedule_signature_verifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('signature_id');
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('decision', 16); // VERIFIED, REJECTED or WITHDRAWN
            $t->text('authority_basis'); // signed authority/contract reference
            $t->text('note');
            $t->uuid('verified_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('signature_id')->references('id')->on('pipe_schedule_external_signatures')->restrictOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('verified_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['signature_id', 'created_at'], 'pipe_ext_signatures_verification_idx');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_external_signatures ADD CONSTRAINT pipe_ext_signatures_fields CHECK (
                length(btrim(signer_name)) >= 2 AND length(btrim(signer_capacity)) >= 2
                AND length(btrim(document_number)) >= 2 AND length(btrim(document_revision)) >= 1
                AND file_sha256 ~ '^[0-9a-fA-F]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_signature_verifications ADD CONSTRAINT pipe_ext_verifications_fields CHECK (
                decision IN ('VERIFIED', 'REJECTED', 'WITHDRAWN')
                AND length(btrim(note)) >= 3
                AND (decision <> 'VERIFIED' OR length(btrim(authority_basis)) >= 10)
            )
        SQL);
        DB::unprepared(<<<'SQL'
            -- migrate:fresh drops tables, not standalone functions. Reinstall the same guard.
            CREATE OR REPLACE FUNCTION epcc_pipe_offline_signature_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'PIPE_OFFLINE_SIGNATURE_APPEND_ONLY' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'pipe_schedule_external_signatures' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM pipe_schedule_exception_requests r
                        JOIN users u ON u.id = NEW.uploaded_by
                        WHERE r.id = NEW.request_id AND r.project_id = NEW.project_id
                          AND r.company_id = NEW.company_id
                          AND u.company_id = NEW.company_id AND u.is_active = true
                    ) THEN
                        RAISE EXCEPTION 'PIPE_OFFLINE_SIGNATURE_SOURCE_INVALID' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NOT EXISTS (
                        SELECT 1 FROM pipe_schedule_external_signatures s
                        JOIN users u ON u.id = NEW.verified_by
                        WHERE s.id = NEW.signature_id AND s.project_id = NEW.project_id
                          AND s.company_id = NEW.company_id AND s.uploaded_by <> NEW.verified_by
                          AND u.company_id = NEW.company_id AND u.is_active = true
                    ) THEN
                        RAISE EXCEPTION 'PIPE_OFFLINE_SIGNATURE_VERIFIER_INVALID' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER pipe_ext_signatures_guard BEFORE INSERT OR UPDATE OR DELETE
                ON pipe_schedule_external_signatures FOR EACH ROW EXECUTE FUNCTION epcc_pipe_offline_signature_guard();
            CREATE TRIGGER pipe_ext_verifications_guard BEFORE INSERT OR UPDATE OR DELETE
                ON pipe_schedule_signature_verifications FOR EACH ROW EXECUTE FUNCTION epcc_pipe_offline_signature_guard();
        SQL);
        foreach (['pipe_schedule_external_signatures', 'pipe_schedule_signature_verifications'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant ON {$table}
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
    }

    public function down(): void
    {
        Schema::dropIfExists('pipe_schedule_signature_verifications');
        Schema::dropIfExists('pipe_schedule_external_signatures');
        DB::statement('DROP FUNCTION IF EXISTS epcc_pipe_offline_signature_guard()');
    }
};
