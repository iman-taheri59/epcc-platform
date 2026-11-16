<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-061: project model-frame revisions and immutable manual component poses. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_model_frame_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('company_id');
            $table->unsignedInteger('revision');
            $table->string('frame_code', 32)->default('PROJECT_MODEL_ENU');
            $table->string('axis_convention', 40)->default('RIGHT_HANDED_ENU_V1');
            $table->string('coordinate_unit', 8)->default('MM');
            $table->text('origin_description');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['project_id', 'revision'], 'model_frame_project_revision_unique');
            $table->index(['project_id', 'frame_code', 'revision'], 'model_frame_project_code_revision_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE project_model_frame_revisions
            ADD CONSTRAINT project_model_frame_revision_positive CHECK (revision > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE project_model_frame_revisions
            ADD CONSTRAINT project_model_frame_contract_fixed CHECK (
                frame_code = 'PROJECT_MODEL_ENU'
                AND axis_convention = 'RIGHT_HANDED_ENU_V1'
                AND coordinate_unit = 'MM'
                AND length(btrim(origin_description)) > 0
            )
        SQL);

        Schema::create('component_pose_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('company_id');
            $table->uuid('piping_line_id');
            $table->uuid('component_id');
            $table->uuid('model_frame_revision_id');
            $table->unsignedInteger('revision');

            $table->string('local_frame_id', 160);
            $table->string('orientation_method', 80);
            $table->double('input_azimuth_deg');
            $table->double('input_inclination_deg');
            $table->double('input_roll_deg');
            $table->jsonb('resolved_angles_deg');
            $table->decimal('anchor_easting_mm', 18, 3);
            $table->decimal('anchor_northing_mm', 18, 3);
            $table->decimal('anchor_elevation_mm', 18, 3);
            $table->string('coordinate_unit', 8)->default('MM');
            $table->jsonb('rotation_local_to_model');

            $table->string('geometry_profile_id', 64);
            $table->string('geometry_profile_revision', 32);
            $table->char('geometry_profile_hash', 64);
            $table->jsonb('resolved_port_geometry');
            $table->string('geometry_status', 16);
            $table->string('unresolved_reason', 64)->nullable();

            // Keep the actor UUID as immutable provenance; deleting a user must
            // not rewrite a historical pose revision through ON DELETE SET NULL.
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            // Component and line identifiers are historical provenance, not
            // lifecycle locks: removing a DRAFT component must not erase or
            // block its immutable pose snapshots. Scope is checked on INSERT
            // below; the complete geometry/input snapshot remains here.
            $table->foreign('model_frame_revision_id')
                ->references('id')->on('project_model_frame_revisions')->restrictOnDelete();

            $table->unique(['component_id', 'revision'], 'component_pose_revision_unique');
            $table->index(['project_id', 'piping_line_id', 'component_id'], 'component_pose_scope_idx');
            $table->index(['model_frame_revision_id'], 'component_pose_frame_revision_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE component_pose_revisions
            ADD CONSTRAINT component_pose_revision_positive CHECK (revision > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE component_pose_revisions
            ADD CONSTRAINT component_pose_local_frame_nonempty CHECK (length(btrim(local_frame_id)) > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE component_pose_revisions
            ADD CONSTRAINT component_pose_method_fixed CHECK (
                orientation_method = 'PROJECT_MODEL_ENU_AZIMUTH_INCLINATION_ROLL_V1'
                AND coordinate_unit = 'MM'
                AND input_inclination_deg >= -90
                AND input_inclination_deg <= 90
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE component_pose_revisions
            ADD CONSTRAINT component_pose_geometry_state_valid CHECK (
                (geometry_status = 'RESOLVED' AND unresolved_reason IS NULL)
                OR (geometry_status = 'UNRESOLVED' AND unresolved_reason IS NOT NULL)
            )
        SQL);

        foreach (['project_model_frame_revisions', 'component_pose_revisions'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_no_update ON {$table}
                AS RESTRICTIVE FOR UPDATE USING (false) WITH CHECK (false)
            SQL);
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_no_delete ON {$table}
                AS RESTRICTIVE FOR DELETE USING (false)
            SQL);
        }

        // RLS protects normal application connections. The trigger is the
        // immutable-history backstop even if a privileged connection is used.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_reject_manual_pose_revision_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION '% rows are immutable; create a new revision', TG_TABLE_NAME;
            END;
            $$;

            CREATE TRIGGER project_model_frame_revisions_immutable
            BEFORE UPDATE OR DELETE ON project_model_frame_revisions
            FOR EACH ROW EXECUTE FUNCTION epcc_reject_manual_pose_revision_mutation();

            CREATE TRIGGER component_pose_revisions_immutable
            BEFORE UPDATE OR DELETE ON component_pose_revisions
            FOR EACH ROW EXECUTE FUNCTION epcc_reject_manual_pose_revision_mutation();
        SQL);

        // A revision is checked against its live sources when it is recorded,
        // then remains a complete historical snapshot if a DRAFT component or
        // line is later removed. This avoids turning existing delete workflows
        // into dangling-FK failures while still rejecting cross-scope writes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_validate_manual_component_pose_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM components c
                    JOIN piping_lines l ON l.id = NEW.piping_line_id
                    WHERE c.id = NEW.component_id
                      AND c.project_id = NEW.project_id
                      AND c.piping_line_id = NEW.piping_line_id
                      AND l.project_id = NEW.project_id
                ) THEN
                    RAISE EXCEPTION 'MANUAL_POSE_COMPONENT_SCOPE_MISMATCH'
                        USING ERRCODE = '23514';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM project_model_frame_revisions f
                    WHERE f.id = NEW.model_frame_revision_id
                      AND f.project_id = NEW.project_id
                ) THEN
                    RAISE EXCEPTION 'MANUAL_POSE_FRAME_SCOPE_MISMATCH'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER component_pose_revisions_scope_check
            BEFORE INSERT ON component_pose_revisions
            FOR EACH ROW EXECUTE FUNCTION epcc_validate_manual_component_pose_revision();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS component_pose_revisions_scope_check ON component_pose_revisions;
            DROP TRIGGER IF EXISTS component_pose_revisions_immutable ON component_pose_revisions;
            DROP TRIGGER IF EXISTS project_model_frame_revisions_immutable ON project_model_frame_revisions;
            DROP POLICY IF EXISTS component_pose_revisions_no_delete ON component_pose_revisions;
            DROP POLICY IF EXISTS component_pose_revisions_no_update ON component_pose_revisions;
            DROP POLICY IF EXISTS component_pose_revisions_archive_freeze ON component_pose_revisions;
            DROP POLICY IF EXISTS component_pose_revisions_tenant_isolation ON component_pose_revisions;
            DROP POLICY IF EXISTS project_model_frame_revisions_no_delete ON project_model_frame_revisions;
            DROP POLICY IF EXISTS project_model_frame_revisions_no_update ON project_model_frame_revisions;
            DROP POLICY IF EXISTS project_model_frame_revisions_archive_freeze ON project_model_frame_revisions;
            DROP POLICY IF EXISTS project_model_frame_revisions_tenant_isolation ON project_model_frame_revisions;
            DROP FUNCTION IF EXISTS epcc_validate_manual_component_pose_revision();
            DROP FUNCTION IF EXISTS epcc_reject_manual_pose_revision_mutation();
        SQL);

        Schema::dropIfExists('component_pose_revisions');
        Schema::dropIfExists('project_model_frame_revisions');
    }
};
