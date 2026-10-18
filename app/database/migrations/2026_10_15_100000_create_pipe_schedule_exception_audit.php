<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-029: append-only evidence; no override is approved by merely placing a pipe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipe_schedule_exception_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('component_id');
            $t->uuid('pms_class_snapshot_id');
            $t->string('expected_schedule', 16);
            $t->string('actual_schedule', 16);
            $t->text('reason');
            $t->uuid('requested_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('component_id')->references('id')->on('components')->restrictOnDelete();
            $t->foreign('pms_class_snapshot_id', 'pipe_schedule_requests_snapshot_fk')
                ->references('id')->on('pms_class_revision_snapshots')->restrictOnDelete();
            $t->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['project_id', 'component_id'], 'pipe_schedule_requests_component_idx');
        });
        Schema::create('pipe_schedule_exception_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('request_id')->unique();
            $t->string('decision', 16);
            $t->text('note');
            $t->uuid('decided_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('request_id')->references('id')->on('pipe_schedule_exception_requests')->restrictOnDelete();
            $t->foreign('decided_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE pipe_schedule_exception_requests ADD CONSTRAINT pipe_schedule_requests_distinct CHECK (expected_schedule <> actual_schedule AND length(btrim(reason)) >= 10)");
        DB::statement("ALTER TABLE pipe_schedule_exception_decisions ADD CONSTRAINT pipe_schedule_decisions_valid CHECK (decision IN ('APPROVED', 'REJECTED') AND length(btrim(note)) >= 3)");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION epcc_pipe_schedule_exception_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'PIPE_SCHEDULE_EXCEPTION_APPEND_ONLY' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'pipe_schedule_exception_requests' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM components c JOIN piping_lines l ON l.id = c.piping_line_id
                        JOIN pms_class_revision_snapshots s ON s.id = NEW.pms_class_snapshot_id
                        JOIN users u ON u.id = NEW.requested_by
                        WHERE c.id = NEW.component_id AND c.project_id = NEW.project_id
                          AND c.company_id = NEW.company_id AND c.template_code = 'PIPE'
                          AND c.schedule = NEW.actual_schedule AND l.id = c.piping_line_id
                          AND l.project_id = NEW.project_id AND l.company_id = NEW.company_id
                          AND l.pms_class_snapshot_id = s.id AND s.project_id = NEW.project_id
                          AND u.company_id = NEW.company_id AND u.is_active = true
                    ) THEN
                        RAISE EXCEPTION 'PIPE_SCHEDULE_EXCEPTION_SOURCE_INVALID' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NOT EXISTS (
                        SELECT 1 FROM pipe_schedule_exception_requests r
                        JOIN users u ON u.id = NEW.decided_by
                        WHERE r.id = NEW.request_id AND r.project_id = NEW.project_id
                          AND r.company_id = NEW.company_id AND r.requested_by <> NEW.decided_by
                          AND u.company_id = NEW.company_id AND u.is_active = true
                    ) THEN
                        RAISE EXCEPTION 'PIPE_SCHEDULE_EXCEPTION_REVIEWER_INVALID' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER pipe_schedule_requests_guard BEFORE INSERT OR UPDATE OR DELETE
                ON pipe_schedule_exception_requests FOR EACH ROW EXECUTE FUNCTION epcc_pipe_schedule_exception_guard();
            CREATE TRIGGER pipe_schedule_decisions_guard BEFORE INSERT OR UPDATE OR DELETE
                ON pipe_schedule_exception_decisions FOR EACH ROW EXECUTE FUNCTION epcc_pipe_schedule_exception_guard();
        SQL);
        foreach (['pipe_schedule_exception_requests', 'pipe_schedule_exception_decisions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant ON {$table}
                USING (
                    project_id IN (
                        SELECT pc.project_id FROM project_company pc
                        WHERE pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                          AND (NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                               OR pc.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
                    )
                )
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
        Schema::dropIfExists('pipe_schedule_exception_decisions');
        Schema::dropIfExists('pipe_schedule_exception_requests');
        DB::statement('DROP FUNCTION IF EXISTS epcc_pipe_schedule_exception_guard()');
    }
};
