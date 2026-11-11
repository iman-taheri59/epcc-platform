<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Let a joint land on a header wall — ADR-006 §14, P-22 implementation.
 *
 * The joint model has always assumed two ports. A fitting-less branch (a
 * stub-in / stub-on, P2P in the branch table) breaks that: the branch pipe's
 * end is welded to a point on the header's WALL, and a pipe has two ends and no
 * port on its side. There is nothing for the second side of the joint to be.
 *
 * The decision recorded in the research (RESEARCH-PLANT3D-PCF-P2P-BRANCHES
 * §4.8) is a FROZEN HEADER REFERENCE, not a synthesised wall port. A fake port
 * would make the header look like it had extra ends, and `openPorts()` — which
 * ADR-009 §2.3 calls the single most useful query in the system, and which
 * drives the worklist, the progress metric and the import validator — would
 * silently start lying about how many ends a line has left.
 *
 * So: `port_b_id` becomes nullable, and the joint instead carries
 * (header component, station along it, offset from its centreline).
 *
 * FOUR INVARIANTS ON `joints` HAD TO BE RESTATED, and every one of them was
 * written for "two ports" and fails SILENTLY on a NULL — which is worse than
 * failing loudly:
 *
 *   - `joints_port_b_unique` and `joints_port_pair_unique`: PostgreSQL treats
 *     NULLs as distinct in a unique index, so leaving them alone would let a
 *     branch be welded an unlimited number of times. Both become PARTIAL
 *     indexes, `WHERE port_b_id IS NOT NULL`.
 *   - `joints_ordered_pair`: `port_a_id < port_b_id` evaluates to NULL when
 *     port_b_id is NULL, and a CHECK that evaluates to NULL PASSES. Restated as
 *     `port_b_id IS NULL OR port_a_id < port_b_id`.
 *   - The cascade: deleting the header used to delete its ports, which deleted
 *     the joints. A wall joint references the COMPONENT, not a port, and a
 *     branch weld does not stop existing because the header was edited. The FK
 *     is RESTRICT: ComponentService::remove() must separate these joints first,
 *     which it already does for port-side joints via jointsTouching().
 *
 * The branch port always goes in `port_a_id`, so `port_a_id` stays NOT NULL and
 * its unique index is untouched — a branch port still cannot be welded twice.
 *
 * No backfill: no project has ever been run on this software.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE joints ALTER COLUMN port_b_id DROP NOT NULL');

        Schema::table('joints', function (Blueprint $table): void {
            // The header pipe this branch is welded onto. NOT a port: the point
            // of connection is described by station and offset, not by an end.
            $table->uuid('branch_header_component_id')->nullable();

            // Distance along the header from its upstream end, millimetres.
            // Plant 3D does not store this because it can read it from the 3D
            // geometry; we have no geometry, so for us an implicit station is
            // no station at all (research §4.4).
            $table->decimal('branch_station_mm', 10, 3)->nullable();

            // Signed distance of the branch centreline from the header
            // centreline, millimetres. 0 is concentric. This is the axis Plant
            // 3D considers worth stating — top tangent, bottom tangent, or a
            // distance — and CADWORX/Cadmatic encode as concentric versus
            // eccentric (research §4.3, §4.5).
            $table->decimal('branch_offset_mm', 8, 3)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_branch_header_fk
                FOREIGN KEY (branch_header_component_id)
                REFERENCES components (id) ON DELETE RESTRICT
        SQL);

        // EXACTLY ONE other side. Without this, a joint with neither a second
        // port nor a header reference is representable, and that is a joint
        // welded to nothing.
        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_other_side CHECK (
                (port_b_id IS NOT NULL AND branch_header_component_id IS NULL)
                OR (port_b_id IS NULL AND branch_header_component_id IS NOT NULL)
            )
        SQL);

        // A header reference without a location is a shrug. The station and the
        // offset are what make this a design rather than a note.
        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_wall_reference_complete CHECK (
                branch_header_component_id IS NULL
                OR (branch_station_mm IS NOT NULL AND branch_offset_mm IS NOT NULL)
            )
        SQL);

        DB::unprepared('DROP INDEX IF EXISTS joints_port_b_unique');
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX joints_port_b_unique ON joints (port_b_id)
                WHERE port_b_id IS NOT NULL
        SQL);

        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_port_pair_unique');
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX joints_port_pair_unique ON joints (port_a_id, port_b_id)
                WHERE port_b_id IS NOT NULL
        SQL);

        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_ordered_pair');
        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_ordered_pair
                CHECK (port_b_id IS NULL OR port_a_id < port_b_id)
        SQL);

        // The guard has to be re-issued: it raised JT_PORT_NOT_FOUND for any
        // joint whose port_b_id did not resolve, which is every wall joint.
        GuardSql::apply(['epcc_guard_joint_line']);
    }

    public function down(): void
    {
        GuardSql::apply(['epcc_guard_joint_line']);

        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_ordered_pair');
        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_ordered_pair
                CHECK (port_a_id < port_b_id)
        SQL);

        DB::unprepared('DROP INDEX IF EXISTS joints_port_pair_unique');
        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_port_pair_unique
                UNIQUE (port_a_id, port_b_id)
        SQL);

        DB::unprepared('DROP INDEX IF EXISTS joints_port_b_unique');
        DB::unprepared('CREATE UNIQUE INDEX joints_port_b_unique ON joints (port_b_id)');

        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_wall_reference_complete');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_other_side');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_header_fk');

        Schema::table('joints', function (Blueprint $table): void {
            $table->dropColumn([
                'branch_header_component_id',
                'branch_station_mm',
                'branch_offset_mm',
            ]);
        });

        DB::statement('ALTER TABLE joints ALTER COLUMN port_b_id SET NOT NULL');
    }
};
