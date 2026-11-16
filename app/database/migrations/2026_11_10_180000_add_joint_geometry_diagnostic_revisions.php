<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-061: append-only, source-fingerprinted snapshots of joint diagnostics. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joint_geometry_diagnostic_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('company_id');
            $table->uuid('piping_line_id');
            // Deliberately no joint FK: a diagnostic revision is historical
            // evidence and must survive deletion of a DRAFT joint/component.
            $table->uuid('joint_id');
            $table->unsignedInteger('revision');

            $table->string('method_code', 80);
            $table->char('source_fingerprint', 64);
            $table->string('diagnostic_status', 16);
            $table->string('diagnostic_reason', 64);
            $table->jsonb('source_snapshot');
            $table->jsonb('diagnostic_snapshot');

            // Preserve the actor UUID as provenance without making user
            // deletion rewrite immutable engineering history.
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['joint_id', 'revision'], 'joint_geom_diag_joint_revision_unique');
            $table->index(
                ['project_id', 'piping_line_id', 'joint_id'],
                'joint_geom_diag_project_line_joint_idx',
            );
            $table->index(['project_id', 'source_fingerprint'], 'joint_geom_diag_source_fingerprint_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE joint_geometry_diagnostic_revisions
            ADD CONSTRAINT joint_geom_diag_revision_positive CHECK (revision > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE joint_geometry_diagnostic_revisions
            ADD CONSTRAINT joint_geom_diag_method_fixed CHECK (
                method_code = 'EXPLICIT_TWO_PORT_PORT_GAP_AXIS_DEVIATION_V1'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE joint_geometry_diagnostic_revisions
            ADD CONSTRAINT joint_geom_diag_fingerprint_sha256 CHECK (
                source_fingerprint ~ '^[0-9a-f]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE joint_geometry_diagnostic_revisions
            ADD CONSTRAINT joint_geom_diag_state_valid CHECK (
                diagnostic_status IN ('UNASSESSED', 'UNRESOLVED', 'STALE')
                AND length(btrim(diagnostic_reason)) > 0
                AND jsonb_typeof(source_snapshot) = 'object'
                AND jsonb_typeof(diagnostic_snapshot) = 'object'
                AND diagnostic_snapshot->>'status' = diagnostic_status
                AND diagnostic_snapshot->>'reason' = diagnostic_reason
            )
        SQL);

        DB::unprepared('ALTER TABLE joint_geometry_diagnostic_revisions ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE joint_geometry_diagnostic_revisions FORCE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            CREATE POLICY joint_geometry_diagnostic_revisions_tenant_isolation
            ON joint_geometry_diagnostic_revisions
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = joint_geometry_diagnostic_revisions.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = joint_geometry_diagnostic_revisions.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE POLICY joint_geometry_diagnostic_revisions_archive_freeze
            ON joint_geometry_diagnostic_revisions
            AS RESTRICTIVE FOR ALL
            USING (true)
            WITH CHECK (epcc_project_is_writable(project_id))
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE POLICY joint_geometry_diagnostic_revisions_no_update
            ON joint_geometry_diagnostic_revisions
            AS RESTRICTIVE FOR UPDATE USING (false) WITH CHECK (false)
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE POLICY joint_geometry_diagnostic_revisions_no_delete
            ON joint_geometry_diagnostic_revisions
            AS RESTRICTIVE FOR DELETE USING (false)
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_reject_joint_geometry_diagnostic_revision_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION '% rows are immutable; create a new revision', TG_TABLE_NAME;
            END;
            $$;

            CREATE TRIGGER joint_geometry_diagnostic_revisions_immutable
            BEFORE UPDATE OR DELETE ON joint_geometry_diagnostic_revisions
            FOR EACH ROW EXECUTE FUNCTION epcc_reject_joint_geometry_diagnostic_revision_mutation();
            SQL);

        // Check the joint scope at insertion, but do not retain a foreign key:
        // topology can be removed in DRAFT while immutable snapshots survive.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_validate_joint_geometry_diagnostic_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM joints j
                    WHERE j.id = NEW.joint_id
                      AND j.project_id = NEW.project_id
                      AND j.piping_line_id = NEW.piping_line_id
                ) THEN
                    RAISE EXCEPTION 'JOINT_GEOMETRY_DIAGNOSTIC_SCOPE_MISMATCH'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER joint_geometry_diagnostic_revisions_scope_check
            BEFORE INSERT ON joint_geometry_diagnostic_revisions
            FOR EACH ROW EXECUTE FUNCTION epcc_validate_joint_geometry_diagnostic_revision();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS joint_geometry_diagnostic_revisions_scope_check
                ON joint_geometry_diagnostic_revisions;
            DROP TRIGGER IF EXISTS joint_geometry_diagnostic_revisions_immutable
                ON joint_geometry_diagnostic_revisions;
            DROP POLICY IF EXISTS joint_geometry_diagnostic_revisions_no_delete
                ON joint_geometry_diagnostic_revisions;
            DROP POLICY IF EXISTS joint_geometry_diagnostic_revisions_no_update
                ON joint_geometry_diagnostic_revisions;
            DROP POLICY IF EXISTS joint_geometry_diagnostic_revisions_archive_freeze
                ON joint_geometry_diagnostic_revisions;
            DROP POLICY IF EXISTS joint_geometry_diagnostic_revisions_tenant_isolation
                ON joint_geometry_diagnostic_revisions;
            DROP FUNCTION IF EXISTS epcc_validate_joint_geometry_diagnostic_revision();
            DROP FUNCTION IF EXISTS epcc_reject_joint_geometry_diagnostic_revision_mutation();
        SQL);

        Schema::dropIfExists('joint_geometry_diagnostic_revisions');
    }
};
