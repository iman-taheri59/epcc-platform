<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DELETED state for everything that carries a tag (the user's ruling, 2026-09-18).
 *
 * WHY A STATE AND NOT A DELETE
 * ----------------------------
 * Phase 3b withdrew automatic numbering because `max(sequence)` cannot see rows
 * that are gone, so deleting `11-P-102` handed that tag to the next pump. That
 * fixed the ALLOCATION side. This fixes the other half: even with tags supplied
 * by hand, a hard DELETE frees the string, and nothing then stops somebody
 * retyping a tag that is printed on an issued isometric.
 *
 * So a tagged thing is never removed. It is marked DELETED and keeps its row -
 * which means it keeps its tag, and the unique index keeps doing its job. The
 * user's choice: **the tag stays reserved.**
 *
 * WHAT THIS IS NOT
 * ----------------
 * Not Laravel's SoftDeletes. That trait hides rows from every query by default,
 * and silent invisibility is the wrong behaviour here: a deleted pump must stay
 * visible in the register, greyed and labelled, because "where did P-101A go?"
 * is a question somebody will ask. A global scope would answer it with silence.
 *
 * It is an explicit state with an audit trail (who, when, why), queried
 * deliberately. `deleted_at` here is a RECORD, not a scope trigger.
 *
 * SCOPE (the user's choice: "anything that has a tag")
 *   equipment          - state gains DELETED
 *   piping_lines       - release_state gains DELETED
 *   equipment_nozzles  - gains a deleted flag; a nozzle tag is unique per
 *                        vessel and appears on the nozzle schedule
 *
 * Components and joints are deliberately excluded. They are modelling objects
 * created and destroyed dozens of times per line during design, they carry no
 * tag that leaves the system, and keeping every one would bloat the largest
 * tables in the schema for no traceability gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------- equipment --
        Schema::table('equipment', function (Blueprint $t): void {
            $t->timestampTz('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->text('deletion_reason')->nullable();

            $t->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::unprepared('ALTER TABLE equipment DROP CONSTRAINT IF EXISTS equipment_state_known');
        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment ADD CONSTRAINT equipment_state_known CHECK (
                state IN ('PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED', 'DELETED')
            )
        SQL);

        /*
         * Provenance, the same rule the line release states already enforce:
         * a state that records WHO did something is worthless if the columns
         * can be left null. Either it is DELETED and we know by whom and when,
         * or it is not deleted and those columns are empty. A half-filled row
         * is the state nobody can audit.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment ADD CONSTRAINT equipment_deletion_has_provenance CHECK (
                (state <> 'DELETED' AND deleted_at IS NULL AND deleted_by IS NULL)
                OR (state = 'DELETED' AND deleted_at IS NOT NULL AND deleted_by IS NOT NULL)
            )
        SQL);

        // The register is read constantly and almost always wants live rows.
        DB::unprepared("CREATE INDEX equipment_live_idx ON equipment (project_id) WHERE state <> 'DELETED'");

        // ----------------------------------------------------- piping_lines --
        Schema::table('piping_lines', function (Blueprint $t): void {
            $t->timestampTz('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->text('deletion_reason')->nullable();

            $t->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::unprepared('ALTER TABLE piping_lines DROP CONSTRAINT IF EXISTS piping_lines_release_state_known');
        DB::unprepared(<<<'SQL'
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_release_state_known CHECK (
                release_state IN ('DRAFT', 'AFC_CONFIRMED', 'RELEASED', 'DELETED')
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_deletion_has_provenance CHECK (
                (release_state <> 'DELETED' AND deleted_at IS NULL AND deleted_by IS NULL)
                OR (release_state = 'DELETED' AND deleted_at IS NOT NULL AND deleted_by IS NOT NULL)
            )
        SQL);

        DB::unprepared("CREATE INDEX piping_lines_live_idx ON piping_lines (project_id) WHERE release_state <> 'DELETED'");

        /*
         * The AFC provenance CHECK predates this state. It reads "anything that
         * is not DRAFT must carry AFC evidence" - which was true when the only
         * other states were AFC_CONFIRMED and RELEASED, and is false now: a
         * DRAFT line withdrawn as a duplicate never passed the AFC gate and
         * never will.
         *
         * Widened rather than dropped. The gate itself is untouched: a line
         * still cannot reach AFC_CONFIRMED or RELEASED without evidence. All
         * that changes is that DELETED, like DRAFT, is not required to have it.
         */
        DB::unprepared('ALTER TABLE piping_lines DROP CONSTRAINT IF EXISTS piping_lines_afc_has_provenance');
        DB::unprepared(<<<'SQL'
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_afc_has_provenance CHECK (
                release_state IN ('DRAFT', 'DELETED')
                OR (afc_confirmed_at IS NOT NULL AND afc_confirmed_by IS NOT NULL)
            )
        SQL);

        // ------------------------------------------------ equipment_nozzles --
        // A nozzle has no state column, and inventing a full state machine for
        // it would be more than the object needs: a nozzle is either there or
        // withdrawn. The flag plus provenance is the whole of it.
        Schema::table('equipment_nozzles', function (Blueprint $t): void {
            $t->boolean('is_deleted')->default(false);
            $t->timestampTz('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->text('deletion_reason')->nullable();

            $t->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment_nozzles ADD CONSTRAINT equipment_nozzles_deletion_has_provenance CHECK (
                (is_deleted = false AND deleted_at IS NULL AND deleted_by IS NULL)
                OR (is_deleted = true AND deleted_at IS NOT NULL AND deleted_by IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS equipment_live_idx');
        DB::unprepared('DROP INDEX IF EXISTS piping_lines_live_idx');

        DB::unprepared('ALTER TABLE equipment DROP CONSTRAINT IF EXISTS equipment_deletion_has_provenance');
        DB::unprepared('ALTER TABLE piping_lines DROP CONSTRAINT IF EXISTS piping_lines_deletion_has_provenance');
        DB::unprepared('ALTER TABLE equipment_nozzles DROP CONSTRAINT IF EXISTS equipment_nozzles_deletion_has_provenance');

        DB::unprepared('ALTER TABLE equipment DROP CONSTRAINT IF EXISTS equipment_state_known');
        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment ADD CONSTRAINT equipment_state_known CHECK (
                state IN ('PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED')
            )
        SQL);

        DB::unprepared('ALTER TABLE piping_lines DROP CONSTRAINT IF EXISTS piping_lines_release_state_known');
        DB::unprepared(<<<'SQL'
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_release_state_known CHECK (
                release_state IN ('DRAFT', 'AFC_CONFIRMED', 'RELEASED')
            )
        SQL);

        DB::unprepared('ALTER TABLE piping_lines DROP CONSTRAINT IF EXISTS piping_lines_afc_has_provenance');
        DB::unprepared(<<<'SQL'
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_afc_has_provenance CHECK (
                release_state = 'DRAFT'
                OR (afc_confirmed_at IS NOT NULL AND afc_confirmed_by IS NOT NULL)
            )
        SQL);

        Schema::table('equipment', function (Blueprint $t): void {
            $t->dropColumn(['deleted_at', 'deleted_by', 'deletion_reason']);
        });

        Schema::table('piping_lines', function (Blueprint $t): void {
            $t->dropColumn(['deleted_at', 'deleted_by', 'deletion_reason']);
        });

        Schema::table('equipment_nozzles', function (Blueprint $t): void {
            $t->dropColumn(['is_deleted', 'deleted_at', 'deleted_by', 'deletion_reason']);
        });
    }
};
