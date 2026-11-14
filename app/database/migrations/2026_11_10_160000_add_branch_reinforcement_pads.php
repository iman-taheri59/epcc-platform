<?php

declare(strict_types=1);

use App\Support\GuardSql;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\P2pKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch reinforcement pad with two independent header-owned fillet joints.
 *
 * The pad is a physical component on the branch line. Its header-side and
 * branch-side interfaces are separate FILLET_WELD joints, each with its own
 * number on the header line. Pad thickness is frozen from the header line's
 * issued PMS snapshot; the ideal contact curve, separate sampled outer
 * perimeter and bounding spans are frozen with their geometry
 * inputs/assumptions, regardless of which line owns the
 * underlying P2P branch-root record.
 *
 * The two future joint IDs are stored on the pad with deferred foreign keys so
 * both welds and the pad can be created atomically without a committed
 * one-sided pad. The deferred constraint trigger verifies their side, target,
 * root, header owner, and connected pad port at transaction end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joints', function (Blueprint $table): void {
            // General target pipe for a pad-side fillet. Original P2P/OLET
            // wall roots continue to use branch_header_component_id.
            $table->uuid('branch_wall_component_id')->nullable();
            $table->uuid('branch_pad_id')->nullable();
            $table->string('branch_pad_side', 16)->nullable();

            $table->foreign('branch_wall_component_id', 'joints_branch_wall_component_fk')
                ->references('id')->on('components')->restrictOnDelete();
            $table->index('branch_wall_component_id', 'joints_branch_wall_component_idx');
            $table->index(['branch_pad_id', 'branch_pad_side'], 'joints_branch_pad_side_idx');
        });

        Schema::create('branch_pads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('company_id');

            // The PAD component belongs to the physical branch line.
            $table->uuid('component_id')->unique('branch_pads_component_unique');
            $table->uuid('branch_root_joint_id')->unique('branch_pads_root_unique');

            $table->uuid('header_pipe_component_id');
            $table->uuid('branch_pipe_component_id');
            // Exact branch-side end at the wall root; this identifies the
            // branch-side pad weld location without inventing pipe geometry.
            $table->uuid('branch_pipe_port_id');
            $table->uuid('header_line_id');
            $table->uuid('pms_class_snapshot_id');

            // Both are NOT NULL; their FKs to joints are deferred below so the
            // pad and its future pair can be inserted in one transaction.
            $table->uuid('header_joint_id')->unique('branch_pads_header_joint_unique');
            $table->uuid('branch_joint_id')->unique('branch_pads_branch_joint_unique');

            $table->string('branch_rule_component_type', 32);
            $table->string('branch_rule_p2p_kind', 16)->nullable();
            $table->decimal('pad_thickness_mm', 8, 3);

            // Bounds of the frozen nominal outer perimeter. The major span is
            // developed circumferentially; the minor span is along the header
            // axis. Neither span is a fabrication tolerance or drawing.
            $table->decimal('pad_major_axis_mm', 10, 3);
            $table->decimal('pad_minor_axis_mm', 10, 3);
            $table->string('pad_major_axis_direction', 32);
            $table->string('pad_minor_axis_direction', 32);
            $table->decimal('pad_geometry_d2_mm', 10, 3);
            $table->decimal('pad_geometry_beta_deg', 5, 2);
            $table->string('pad_geometry_method', 48);
            $table->jsonb('pad_geometry_snapshot');

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('component_id', 'branch_pads_component_fk')
                ->references('id')->on('components')->cascadeOnDelete();
            $table->foreign('branch_root_joint_id', 'branch_pads_root_fk')
                ->references('id')->on('joints')->restrictOnDelete();
            $table->foreign('header_pipe_component_id', 'branch_pads_header_pipe_fk')
                ->references('id')->on('components')->restrictOnDelete();
            $table->foreign('branch_pipe_component_id', 'branch_pads_branch_pipe_fk')
                ->references('id')->on('components')->restrictOnDelete();
            $table->foreign('branch_pipe_port_id', 'branch_pads_branch_pipe_port_fk')
                ->references('id')->on('ports')->restrictOnDelete();
            $table->foreign('header_line_id', 'branch_pads_header_line_fk')
                ->references('id')->on('piping_lines')->restrictOnDelete();
            $table->foreign('pms_class_snapshot_id', 'branch_pads_pms_snapshot_fk')
                ->references('id')->on('pms_class_revision_snapshots')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['project_id', 'header_line_id'], 'branch_pads_project_header_line_idx');
            $table->index(['project_id', 'branch_pipe_component_id'], 'branch_pads_project_branch_pipe_idx');
        });

        $branchTypes = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            BranchConnectionType::ALL,
        ));
        $p2pKinds = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            P2pKind::ALL,
        ));

        DB::unprepared(<<<SQL
            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_rule_type_valid
                CHECK (branch_rule_component_type IN ({$branchTypes}));

            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_rule_p2p_kind_valid
                CHECK (
                    (branch_rule_component_type = 'P2P'
                     AND branch_rule_p2p_kind IS NOT NULL
                     AND branch_rule_p2p_kind IN ({$p2pKinds}))
                    OR
                    (branch_rule_component_type <> 'P2P'
                     AND branch_rule_p2p_kind IS NULL)
                );

            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_thickness_positive
                CHECK (pad_thickness_mm > 0);

            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_saddle_axes_valid
                CHECK (
                    pad_minor_axis_mm > 0
                    AND pad_major_axis_mm > pad_minor_axis_mm
                    AND pad_major_axis_direction = 'AROUND_HEADER'
                    AND pad_minor_axis_direction = 'ALONG_HEADER'
                    AND pad_geometry_d2_mm > 0
                    AND pad_geometry_beta_deg = 90.00
                    AND pad_geometry_method = 'CENTERED_90_DEGREE_SADDLE_PERIMETER_V2'
                    AND jsonb_typeof(pad_geometry_snapshot) = 'object'
                );

            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_distinct_joints
                CHECK (header_joint_id <> branch_joint_id);

            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_header_joint_fk
                FOREIGN KEY (header_joint_id) REFERENCES joints (id)
                ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED;

            ALTER TABLE branch_pads ADD CONSTRAINT branch_pads_branch_joint_fk
                FOREIGN KEY (branch_joint_id) REFERENCES joints (id)
                ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED;
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_branch_pad_fk
                FOREIGN KEY (branch_pad_id) REFERENCES branch_pads (id) ON DELETE RESTRICT;

            ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_other_side;
            ALTER TABLE joints ADD CONSTRAINT joints_other_side CHECK (
                (port_b_id IS NOT NULL
                 AND branch_header_component_id IS NULL
                 AND branch_wall_component_id IS NULL
                 AND branch_pad_id IS NULL
                 AND branch_pad_side IS NULL)
                OR
                (port_b_id IS NULL
                 AND branch_header_component_id IS NOT NULL
                 AND branch_wall_component_id IS NULL
                 AND branch_pad_id IS NULL
                 AND branch_pad_side IS NULL)
                OR
                (port_b_id IS NULL
                 AND branch_header_component_id IS NULL
                 AND branch_wall_component_id IS NOT NULL
                 AND branch_pad_id IS NOT NULL
                 AND branch_pad_side IN ('HEADER', 'BRANCH'))
            );

            ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_wall_reference_complete;
            ALTER TABLE joints ADD CONSTRAINT joints_wall_reference_complete CHECK (
                (branch_header_component_id IS NULL
                 AND branch_wall_component_id IS NULL
                 AND branch_station_mm IS NULL
                 AND branch_offset_mm IS NULL)
                OR
                (branch_header_component_id IS NOT NULL
                 AND branch_wall_component_id IS NULL
                 AND branch_station_mm IS NOT NULL
                 AND branch_offset_mm IS NOT NULL)
                OR
                (branch_header_component_id IS NULL
                 AND branch_wall_component_id IS NOT NULL
                 AND branch_pad_id IS NOT NULL
                 AND branch_pad_side = 'HEADER'
                 AND branch_station_mm IS NOT NULL
                 AND branch_offset_mm IS NOT NULL)
                OR
                (branch_header_component_id IS NULL
                 AND branch_wall_component_id IS NOT NULL
                 AND branch_pad_id IS NOT NULL
                 AND branch_pad_side = 'BRANCH'
                 AND branch_station_mm IS NULL
                 AND branch_offset_mm IS NULL)
            );

            ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_root_requires_two_ports;
            ALTER TABLE joints ADD CONSTRAINT joints_branch_root_requires_two_ports CHECK (
                branch_root_joint_id IS NULL
                OR (port_b_id IS NOT NULL AND branch_pad_id IS NULL)
                OR (port_b_id IS NULL AND branch_pad_id IS NOT NULL
                    AND branch_pad_side IN ('HEADER', 'BRANCH'))
            );

            ALTER TABLE joints ADD CONSTRAINT joints_fillet_weld_requires_branch_pad
                CHECK (joint_type <> 'FILLET_WELD' OR branch_pad_id IS NOT NULL);

            ALTER TABLE joints ADD CONSTRAINT joints_branch_pad_side_valid CHECK (
                (branch_pad_id IS NULL
                 AND branch_pad_side IS NULL
                 AND branch_wall_component_id IS NULL)
                OR
                (branch_pad_id IS NOT NULL
                 AND branch_pad_side IN ('HEADER', 'BRANCH')
                 AND branch_wall_component_id IS NOT NULL
                 AND branch_header_component_id IS NULL
                 AND port_b_id IS NULL
                 AND branch_root_joint_id IS NOT NULL
                 AND joint_type = 'FILLET_WELD'
                 AND numbering_policy = 'REQUIRED'
                 AND joint_seq IS NOT NULL)
            );
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX joints_branch_pad_side_unique
                ON joints (branch_pad_id, branch_pad_side)
                WHERE branch_pad_id IS NOT NULL
        SQL);

        // Branch pads use the same tenant and archive protections as components
        // and joints; neither a co-tenant nor an archived project can mutate one.
        DB::unprepared('ALTER TABLE branch_pads ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE branch_pads FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY branch_pads_tenant_isolation ON branch_pads
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR branch_pads.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = branch_pads.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = branch_pads.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            );

            CREATE POLICY branch_pads_archive_freeze ON branch_pads
            AS RESTRICTIVE FOR ALL
            USING (true)
            WITH CHECK (epcc_project_is_writable(project_id));

            CREATE POLICY branch_pads_archive_no_delete ON branch_pads
            AS RESTRICTIVE FOR DELETE
            USING (epcc_project_is_writable(project_id));
        SQL);

        // The line guard now verifies both pad sides, the selected header-line
        // owner, and the root/pad/component relationship. Apply its one canonical
        // definition; do not redeclare this guard in a migration.
        GuardSql::apply(['epcc_guard_joint_line']);

        GuardSql::apply(['epcc_guard_branch_pad_complete']);
        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER branch_pads_complete
                AFTER INSERT OR UPDATE ON branch_pads
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_branch_pad_complete();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Branch pads and their two separately numbered welds are physical register records; this migration is forward-only.'
        );
    }
};
