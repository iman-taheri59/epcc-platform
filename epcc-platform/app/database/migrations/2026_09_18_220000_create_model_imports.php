<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3D model ingestion via PCF (ADR-005 phase 2).
 *
 * The 3D model itself is unreadable - NWD/NWF/NWC are proprietary and
 * Windows-only - so the import path is the Piping Component File, which every
 * major plant tool emits and which carries the connectivity, not just a parts
 * list.
 *
 * Three user rulings shape this schema (2026-09-18):
 *
 *   1. A re-import DIFFS rather than replaces. Anything carrying recorded
 *      progress is never touched; the difference is reported instead. Hence
 *      `model_import_diffs`, which is a report and not a staging table.
 *   2. An unknown line number is QUARANTINED, not created outright and not
 *      refused. Hence `piping_lines.is_quarantined`.
 *   3. Imported objects stay hand-editable but are FLAGGED once touched, so
 *      the next import can tell "the model changed" from "a person changed
 *      this". Hence `manually_edited_at` on components and ports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_imports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_line_id')->nullable();

            $t->string('filename');
            $t->string('source_kind')->default('PCF');

            // The file's own identity, so a re-import of the SAME revision can
            // be recognised as such. Vendors bump REVISION on export.
            $t->string('pipeline_reference')->nullable();
            $t->string('revision')->nullable();
            $t->string('piping_spec')->nullable();

            // Recorded because they cannot be assumed. A file in
            // INCH-SIXTEENTH read as INCH turns 6" into 96" and every
            // downstream number stays plausible.
            $t->string('units_bore')->nullable();
            $t->string('units_coords')->nullable();

            // SHA-256 of the file. Two purposes: recognising a byte-identical
            // re-upload, and proving months later exactly which file produced
            // the model - the isometric in the field is signed, so the input
            // to it must be identifiable too.
            $t->string('content_hash', 64);

            $t->string('state')->default('PARSED');

            $t->integer('components_seen')->default(0);
            $t->integer('components_created')->default(0);
            $t->integer('components_updated')->default(0);
            $t->integer('components_unchanged')->default(0);
            $t->integer('components_protected')->default(0);
            $t->integer('joints_created')->default(0);
            $t->integer('unmapped_count')->default(0);

            $t->jsonb('warnings')->nullable();

            $t->uuid('imported_by');
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_line_id')->references('id')->on('piping_lines')->nullOnDelete();
            $t->foreign('imported_by')->references('id')->on('users')->restrictOnDelete();

            $t->index(['project_id', 'created_at']);
            $t->index(['piping_line_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE model_imports ADD CONSTRAINT model_imports_state_known CHECK (
                state IN ('PARSED', 'APPLIED', 'FAILED', 'DRY_RUN')
            )
        SQL);

        /*
         * The diff report. One row per difference the import found.
         *
         * This exists because of ruling 1: a re-import must not silently
         * overwrite. Where it CAN act it acts and records what it did; where
         * progress is recorded it stops and records what it would have done.
         * The second kind is the valuable one - it is the list an engineer
         * has to look at, and without a table it would be a log message
         * nobody ever reads.
         */
        Schema::create('model_import_diffs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('model_import_id');
            $t->uuid('component_id')->nullable();

            $t->string('change');       // CREATED | UPDATED | UNCHANGED | PROTECTED | REMOVED_IN_MODEL | UNMAPPED
            $t->string('pcf_type')->nullable();
            $t->string('template_code')->nullable();
            $t->integer('source_line')->nullable();
            $t->string('detail')->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();

            // Set when the change was withheld because progress exists.
            $t->string('protected_reason')->nullable();
            $t->boolean('acknowledged')->default(false);
            $t->uuid('acknowledged_by')->nullable();
            $t->timestamp('acknowledged_at')->nullable();

            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('model_import_id')->references('id')->on('model_imports')->cascadeOnDelete();
            $t->foreign('component_id')->references('id')->on('components')->nullOnDelete();
            $t->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['model_import_id', 'change']);
            $t->index(['project_id', 'acknowledged']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE model_import_diffs ADD CONSTRAINT model_import_diffs_change_known CHECK (
                change IN ('CREATED', 'UPDATED', 'UNCHANGED', 'PROTECTED', 'REMOVED_IN_MODEL', 'UNMAPPED')
            )
        SQL);

        // A withheld change must say why, or the report is just a shrug.
        DB::unprepared(<<<'SQL'
            ALTER TABLE model_import_diffs ADD CONSTRAINT model_import_diffs_protected_has_reason CHECK (
                change <> 'PROTECTED' OR protected_reason IS NOT NULL
            )
        SQL);

        Schema::table('components', function (Blueprint $t): void {
            $t->uuid('model_import_id')->nullable()->after('external_ref');

            // Ruling 3: hand edits are allowed and recorded. `manually_edited_at`
            // is what makes the NEXT import able to say "you changed this and so
            // did the model" instead of picking a winner in silence.
            $t->timestamp('manually_edited_at')->nullable()->after('model_import_id');
            $t->uuid('manually_edited_by')->nullable()->after('manually_edited_at');

            // Stable identity from the file, so a component can be recognised
            // across revisions without relying on its position in the list.
            $t->string('pcf_identifier')->nullable()->after('manually_edited_by');
            $t->string('pcf_type')->nullable()->after('pcf_identifier');
            $t->string('spool_identifier')->nullable()->after('pcf_type');

            $t->foreign('model_import_id')->references('id')->on('model_imports')->nullOnDelete();
            $t->foreign('manually_edited_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['piping_line_id', 'pcf_identifier']);
        });

        Schema::table('ports', function (Blueprint $t): void {
            // Coordinates in MILLIMETRES, normalised on the way in. Storing the
            // file's own units would make two files of different UNITS-CO-ORDS
            // incomparable, which is exactly when connectivity matters most.
            $t->decimal('x_mm', 16, 3)->nullable()->after('facing');
            $t->decimal('y_mm', 16, 3)->nullable()->after('x_mm');
            $t->decimal('z_mm', 16, 3)->nullable()->after('y_mm');
            $t->timestamp('manually_edited_at')->nullable()->after('z_mm');

            $t->index(['project_id', 'x_mm', 'y_mm', 'z_mm']);
        });

        Schema::table('piping_lines', function (Blueprint $t): void {
            // Ruling 2. A line the model knows about but the line list does not
            // is admitted, and then held at arm's length until somebody with
            // authority accepts it.
            $t->boolean('is_quarantined')->default(false)->after('release_state');
            $t->string('quarantine_reason')->nullable()->after('is_quarantined');
            $t->timestamp('released_from_quarantine_at')->nullable()->after('quarantine_reason');
            $t->uuid('released_from_quarantine_by')->nullable()->after('released_from_quarantine_at');

            $t->foreign('released_from_quarantine_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE piping_lines ADD CONSTRAINT piping_lines_quarantine_release_is_provenanced CHECK (
                is_quarantined = true
                OR released_from_quarantine_at IS NULL
                OR released_from_quarantine_by IS NOT NULL
            )
        SQL);

        // A quarantined line is not real work yet: it must not appear in a
        // progress count, so give the register a cheap way to exclude it.
        DB::unprepared(
            "CREATE INDEX piping_lines_quarantined_idx ON piping_lines (project_id) WHERE is_quarantined = true"
        );

        foreach (['model_imports', 'model_import_diffs'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant ON {$table}
                USING (project_id IN (SELECT project_id FROM project_memberships
                       WHERE company_id = current_setting('epcc.company_id', true)::uuid))
                WITH CHECK (project_id IN (SELECT project_id FROM project_memberships
                       WHERE company_id = current_setting('epcc.company_id', true)::uuid))
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS piping_lines_quarantined_idx');
        DB::unprepared('ALTER TABLE piping_lines DROP CONSTRAINT IF EXISTS piping_lines_quarantine_release_is_provenanced');

        Schema::table('piping_lines', function (Blueprint $t): void {
            $t->dropForeign(['released_from_quarantine_by']);
            $t->dropColumn([
                'is_quarantined', 'quarantine_reason',
                'released_from_quarantine_at', 'released_from_quarantine_by',
            ]);
        });

        Schema::table('ports', function (Blueprint $t): void {
            $t->dropIndex(['project_id', 'x_mm', 'y_mm', 'z_mm']);
            $t->dropColumn(['x_mm', 'y_mm', 'z_mm', 'manually_edited_at']);
        });

        Schema::table('components', function (Blueprint $t): void {
            $t->dropForeign(['model_import_id']);
            $t->dropForeign(['manually_edited_by']);
            $t->dropIndex(['piping_line_id', 'pcf_identifier']);
            $t->dropColumn([
                'model_import_id', 'manually_edited_at', 'manually_edited_by',
                'pcf_identifier', 'pcf_type', 'spool_identifier',
            ]);
        });

        Schema::dropIfExists('model_import_diffs');
        Schema::dropIfExists('model_imports');
    }
};
