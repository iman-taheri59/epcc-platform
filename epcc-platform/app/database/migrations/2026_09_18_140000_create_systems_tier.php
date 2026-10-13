<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 revision — the System tier, and unit_code becomes mandatory.
 *
 * The user's breakdown diagram (2026-09-18) has FIVE levels:
 *
 *     Project -> Plant/Complex -> Unit/Area -> System -> Sub-system
 *
 * ADR-004 built four. The System level was never rejected - it was never
 * considered, which is worse, because a deliberate omission gets revisited and
 * an accidental one does not.
 *
 * WHY IT IS NOT DECORATION
 * ------------------------
 * A system is the FUNCTIONAL grouping ("Fuel Gas System"); an area is the
 * GEOGRAPHIC one. They do not nest cleanly in reality - one fuel gas system
 * crosses three areas - but commissioning is organised by system and
 * construction by area, and every handover argument on a project is some
 * version of that mismatch. Without the tier, a subsystem's only parent is
 * geographic, so "what is left before Fuel Gas can be commissioned?" has no
 * query. Nobody notices until commissioning starts, by which point there are
 * tens of thousands of rows to re-parent by hand.
 *
 * WHY MANDATORY (the user chose this over optional)
 * -------------------------------------------------
 * Area is nullable and that was right - a small plant genuinely has none. A
 * system is different: every tagged asset belongs to some functional system,
 * even if the project calls it "General". Making it optional would let two
 * projects grow incompatible shapes, and every roll-up would then need a
 * two-branch COALESCE - the same reasoning that rejected sibling port tables
 * in ADR-010 section 3.2.
 *
 * WHY unit_code STOPS BEING NULLABLE
 * ----------------------------------
 * The user confirmed "Area IS the Unit", and the equipment tag is built as
 * [Unit]-[Type]-[Sequence]. A nullable unit_code means a tag that cannot be
 * composed, discovered at tagging time rather than at setup time. Offsite and
 * utility facilities get a real code too ('00', '80', '90') rather than a null
 * - which is exactly how IPS-E-PR-308 and the NPC/DEP schemes handle them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // 1. systems
        // -------------------------------------------------------------------
        Schema::create('systems', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            // A system hangs off the FACILITY, not the area. It is functional,
            // and it legitimately spans several areas - forcing it under one
            // would make the commissioning boundary a lie.
            $t->uuid('facility_id');

            $t->string('code', 64);
            $t->string('name_en');
            $t->string('name_fa');

            // Commissioning sequence. Systems are handed over in an order
            // (utilities before process), and that order is a property of the
            // system, not something to re-derive from a schedule.
            $t->integer('commissioning_sequence')->nullable();

            $t->string('external_ref', 128)->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();

            // Same scope rule as areas: unique per facility, not per project.
            // Two plants in one project both legitimately have a "FG" system.
            $t->unique(['facility_id', 'code']);
        });

        // -------------------------------------------------------------------
        // 2. subsystems.system_id
        // -------------------------------------------------------------------
        Schema::table('subsystems', function (Blueprint $t): void {
            $t->uuid('system_id')->nullable()->after('facility_id');
        });

        /*
         * Backfill, in SQL and with RLS off for the duration.
         *
         * Eloquent is bound by RLS and there is no tenant set during a
         * migration; `subsystems` carries FORCE ROW LEVEL SECURITY, which
         * applies to the owner too. The trap recorded in HANDOFF section 8 is
         * that a backfill under FORCE RLS silently matches ZERO rows - it does
         * not error, so the migration "succeeds" and the data is untouched.
         */
        // EVERY table the backfill READS must be disabled too, not just the
        // one it writes. The SELECT below reads `facilities` and the EXISTS
        // subquery reads `subsystems`; with no tenant GUC set, an RLS-protected
        // table returns ZERO ROWS rather than erroring, so the INSERT succeeds
        // having created nothing and the NOT NULL check then fails on every
        // pre-existing row. Verified the hard way: `INSERT 0 0`.
        foreach (['systems', 'subsystems', 'facilities', 'areas'] as $t) {
            DB::unprepared("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
        }

        // One default system per facility that has subsystems. Named GEN, not
        // "DEFAULT": a code that appears on a certificate should read like
        // something an engineer wrote.
        DB::unprepared(<<<'SQL'
            INSERT INTO systems (id, project_id, company_id, facility_id, code, name_en, name_fa, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                f.project_id,
                f.company_id,
                f.id,
                'GEN',
                'General',
                'عمومی',
                now(),
                now()
            FROM facilities f
            WHERE EXISTS (SELECT 1 FROM subsystems s WHERE s.facility_id = f.id)
        SQL);

        DB::unprepared(<<<'SQL'
            UPDATE subsystems s
            SET system_id = sy.id
            FROM systems sy
            WHERE sy.facility_id = s.facility_id
              AND sy.code = 'GEN'
              AND s.system_id IS NULL
        SQL);

        // A backfill that matched nothing must not proceed to add a NOT NULL
        // constraint - the failure would otherwise surface as an opaque 23514
        // rather than as "the backfill did not run".
        $unassigned = (int) DB::selectOne(
            'SELECT count(*) AS c FROM subsystems WHERE facility_id IS NOT NULL AND system_id IS NULL'
        )->c;

        if ($unassigned > 0) {
            throw new RuntimeException(
                "System backfill matched no rows for {$unassigned} subsystem(s). ".
                'This is almost always row-level security hiding the source tables '.
                '(HANDOFF section 8): every table the backfill READS must be disabled, not just the target.'
            );
        }

        // Any subsystem with no facility cannot be given a system, and a
        // NOT NULL below would fail on it. ADR-004 made facility_id nullable
        // for the same historical reason; this migration does not change that,
        // so the constraint has to tolerate it.
        $orphans = (int) DB::selectOne(
            'SELECT count(*) AS c FROM subsystems WHERE facility_id IS NULL'
        )->c;

        foreach (['systems', 'subsystems', 'facilities'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        }

        /*
         * The composite FK, exactly as ADR-004 did for areas: it makes
         * "subsystem in system A, but system A belongs to another facility"
         * unrepresentable rather than merely discouraged. A corruption no
         * screen would reveal but every roll-up would get wrong.
         */
        DB::unprepared('CREATE UNIQUE INDEX systems_id_facility_id_unique ON systems (id, facility_id)');
        DB::unprepared(<<<'SQL'
            ALTER TABLE subsystems
            ADD CONSTRAINT subsystems_system_within_facility
            FOREIGN KEY (system_id, facility_id)
            REFERENCES systems (id, facility_id)
            ON DELETE RESTRICT
        SQL);

        // Mandatory, but only where a facility exists to hang it from.
        DB::unprepared(<<<'SQL'
            ALTER TABLE subsystems ADD CONSTRAINT subsystems_system_required CHECK (
                facility_id IS NULL OR system_id IS NOT NULL
            )
        SQL);

        DB::unprepared('CREATE INDEX subsystems_system_idx ON subsystems (system_id)');

        // -------------------------------------------------------------------
        // 3. areas.unit_code becomes mandatory - Area IS the Unit
        // -------------------------------------------------------------------
        // areas is still disabled from the block above.
        // Backfill from the area code before demanding NOT NULL. Inventing a
        // sequence here would be worse than reusing the code the project
        // already chose.
        DB::unprepared("UPDATE areas SET unit_code = code WHERE unit_code IS NULL OR btrim(unit_code) = ''");

        DB::unprepared('ALTER TABLE areas ENABLE ROW LEVEL SECURITY');

        DB::unprepared('ALTER TABLE areas ALTER COLUMN unit_code SET NOT NULL');
        DB::unprepared(<<<'SQL'
            ALTER TABLE areas ADD CONSTRAINT areas_unit_code_not_blank CHECK (btrim(unit_code) <> '')
        SQL);

        // The unit code is what goes INTO an equipment tag, so it must be
        // unique within the facility - two areas sharing '11' would make
        // 11-P-101A ambiguous, which is the exact failure the user described.
        DB::unprepared('CREATE UNIQUE INDEX areas_facility_unit_code_unique ON areas (facility_id, unit_code)');

        // -------------------------------------------------------------------
        // 4. RLS on systems - copied from areas, not reinvented
        // -------------------------------------------------------------------
        DB::unprepared('ALTER TABLE systems FORCE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            CREATE POLICY systems_tenant_isolation ON systems
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR systems.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = systems.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = systems.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY systems_archive_freeze ON systems
            AS RESTRICTIVE
            FOR ALL
            USING (true)
            WITH CHECK (epcc_project_is_writable(project_id))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY systems_archive_no_delete ON systems
            AS RESTRICTIVE
            FOR DELETE
            USING (epcc_project_is_writable(project_id))
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE subsystems DROP CONSTRAINT IF EXISTS subsystems_system_within_facility');
        DB::unprepared('ALTER TABLE subsystems DROP CONSTRAINT IF EXISTS subsystems_system_required');
        DB::unprepared('DROP INDEX IF EXISTS subsystems_system_idx');

        Schema::table('subsystems', function (Blueprint $t): void {
            $t->dropColumn('system_id');
        });

        DB::unprepared('DROP INDEX IF EXISTS areas_facility_unit_code_unique');
        DB::unprepared('ALTER TABLE areas DROP CONSTRAINT IF EXISTS areas_unit_code_not_blank');
        DB::unprepared('ALTER TABLE areas ALTER COLUMN unit_code DROP NOT NULL');

        Schema::dropIfExists('systems');
    }
};
