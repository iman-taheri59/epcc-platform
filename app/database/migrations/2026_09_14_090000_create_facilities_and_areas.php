<?php

declare(strict_types=1);

use App\Support\FacilityKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-004: the physical breakdown that was missing.
 *
 * Until now `subsystems` hung directly off `projects` - a flat middle, so a
 * project with 4,000 subsystems had no intermediate grouping at all. The user
 * asked for project -> plant -> subsystem, compatible with AutoCAD Plant 3D.
 *
 * Two new tables:
 *
 *   facilities  - a commissioning parent: a plant, an offsite, a marine
 *                 facility, a utility block. PIPELINE exists as a kind but is
 *                 deferred to a later phase by the user's decision.
 *   areas       - the OPTIONAL level below a facility. This is Plant 3D's
 *                 "Area" and AWP's "CWA", the level awp.pack.json already
 *                 references and previously had nowhere to live.
 *
 * Why one `facilities` table with a kind discriminator rather than separate
 * `plants` and `pipelines` tables: see App\Support\FacilityKind.
 *
 * Why `areas` is optional: Plant 3D's own refinery example has 13 areas, so
 * dropping the level would break lossless import from it. But an Iranian gas
 * booster station with 12 subsystems should not have to invent 12 areas to use
 * the software. `subsystems.area_id` is therefore nullable and a subsystem may
 * attach straight to its facility. Absence of structure must not be reported as
 * an error - the same rule as closurePercent() returning 100 for an empty punch
 * list.
 *
 * ---------------------------------------------------------------------------
 * The subsystem numbering change (user decision)
 * ---------------------------------------------------------------------------
 * `subsystems` previously had UNIQUE(project_id, code). The user chose
 * per-facility uniqueness, because two plants in one project legitimately both
 * want SS-2201. So that constraint is replaced by UNIQUE(facility_id, code).
 *
 * This is a real trade: the displayed identifier must now include the facility
 * to stay unambiguous, which is what Subsystem::fullCode() is for. Certificates
 * already issued keep the code they were issued with, because McCertificate
 * stores its own snapshot - it does not recompute the subsystem code on render.
 *
 * ---------------------------------------------------------------------------
 * Backfill
 * ---------------------------------------------------------------------------
 * `subsystems.facility_id` is NOT NULL: a subsystem with no parent is exactly
 * the flat structure this migration exists to remove, and making it nullable
 * would let that state persist silently. Existing rows are therefore given a
 * default facility per project, created here. On the seeded fixture this is a
 * handful of rows; on a real installation it keeps the column honest without
 * asking anyone to hand-map first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kinds = "'".implode("','", FacilityKind::all())."'";

        // -------------------------------------------------------------------
        // facilities
        // -------------------------------------------------------------------
        Schema::create('facilities', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The company responsible for delivering it - normally the EPC.
            // Same RLS tenant column as subsystems, and reads are open to every
            // participant for the same reason: a breakdown is not commercial
            // data, it is the map everyone works from.
            $t->uuid('company_id');

            $t->string('code', 64);
            $t->string('name_en');
            $t->string('name_fa');

            $t->string('kind', 16);

            /*
             * Per-kind spatial addressing. A plant records its coordinates or
             * grid; a pipeline would record from_kp/to_kp. Storing these as
             * columns would mean dead columns on every row of the other kind,
             * so they live in a validated jsonb - the same choice already made
             * for punch_summary and exception_items.
             *
             * Empty object rather than null: a caller reading addressing['x']
             * on a fresh row should get null, not a TypeError.
             */
            $t->jsonb('addressing')->default('{}');

            /*
             * Where this facility came from if it was imported rather than
             * typed - a Plant 3D PnPID, an area code from a drawing property.
             * Nullable and unconstrained on purpose: it is a breadcrumb for
             * round-tripping (ADR-004 §4, ADR-005), not a key we join on.
             */
            $t->string('external_ref', 128)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            // A facility code is unique within its project. Unlike subsystems,
            // there is no level above to disambiguate, so project-wide is the
            // only correct scope here.
            $t->unique(['project_id', 'code']);
            $t->index(['project_id', 'kind']);
        });

        DB::unprepared("ALTER TABLE facilities ADD CONSTRAINT facilities_kind_check CHECK (kind IN ($kinds))");

        // -------------------------------------------------------------------
        // areas - the optional level
        // -------------------------------------------------------------------
        Schema::create('areas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('facility_id');

            $t->string('code', 64);
            $t->string('name_en');
            $t->string('name_fa');

            /*
             * Plant 3D carries Area and Unit as two separate tag subparts, and
             * they are not the same thing: one plant area can host more than
             * one process unit. Keeping unit_code beside code means an import
             * does not have to flatten them into one string and lose the
             * distinction.
             */
            $t->string('unit_code', 64)->nullable();

            $t->string('external_ref', 128)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();

            $t->unique(['facility_id', 'code']);
        });

        // -------------------------------------------------------------------
        // subsystems: attach to the new levels
        // -------------------------------------------------------------------
        Schema::table('subsystems', function (Blueprint $t): void {
            $t->uuid('facility_id')->nullable()->after('project_id');
            $t->uuid('area_id')->nullable()->after('facility_id');
        });

        /*
         * Backfill. Every project that already has subsystems gets one default
         * facility, and its subsystems are attached to it.
         *
         * Done in SQL rather than through Eloquent because models are bound by
         * RLS and this runs with no tenant set - and because a migration that
         * depends on application code breaks the moment that code changes.
         *
         * RLS has to come off for the duration. `subsystems` carries FORCE ROW
         * LEVEL SECURITY, which applies to the table owner too, and a migration
         * runs with no `epcc.company_id` set - so every SELECT here returns
         * zero rows and the backfill silently does nothing. The first attempt
         * at this migration failed exactly that way: 23502, "facility_id
         * contains null values", on a table the migration had just tried to
         * populate.
         *
         * Toggling it off is safe here and nowhere else: DDL is transactional
         * in PostgreSQL, so if anything below throws, the re-enable is rolled
         * back along with the rest and the table cannot be left exposed. The
         * flags are restored explicitly rather than assumed, immediately after
         * the loop.
         */
        DB::unprepared('ALTER TABLE subsystems DISABLE ROW LEVEL SECURITY');

        $projects = DB::table('subsystems')
            ->select('project_id')
            ->distinct()
            ->pluck('project_id');

        foreach ($projects as $projectId) {
            // Inherit the company from the subsystems themselves rather than
            // guessing the project owner: the delivering company is the one
            // that must be able to write to the facility under RLS.
            $companyId = DB::table('subsystems')
                ->where('project_id', $projectId)
                ->value('company_id');

            $projectCode = DB::table('projects')->where('id', $projectId)->value('code');

            $facilityId = (string) Str::uuid();

            DB::table('facilities')->insert([
                'id' => $facilityId,
                'project_id' => $projectId,
                'company_id' => $companyId,
                'code' => $projectCode.'-PLANT-01',
                'name_en' => 'Main plant',
                'name_fa' => 'واحد اصلی',
                'kind' => FacilityKind::PLANT,
                'addressing' => '{}',
                'external_ref' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('subsystems')
                ->where('project_id', $projectId)
                ->update(['facility_id' => $facilityId]);
        }

        // Restore both flags. ENABLE alone would leave FORCE off, which would
        // quietly exempt the owning role from its own policies - the exact
        // hole standing rule 2 exists to prevent.
        DB::unprepared('ALTER TABLE subsystems ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE subsystems FORCE ROW LEVEL SECURITY');

        // Only now can the column be made mandatory. If the backfill above had
        // been a no-op, this is the line that fails - and it is deliberately
        // left to fail loudly rather than making the column nullable.
        DB::unprepared('ALTER TABLE subsystems ALTER COLUMN facility_id SET NOT NULL');

        Schema::table('subsystems', function (Blueprint $t): void {
            $t->foreign('facility_id')->references('id')->on('facilities')->cascadeOnDelete();
            // An area may be deleted without taking its subsystems with it -
            // they fall back to hanging off the facility, which is a valid
            // state. Hence nullOnDelete rather than cascade.
            $t->foreign('area_id')->references('id')->on('areas')->nullOnDelete();
        });

        /*
         * Swap the uniqueness scope: per-facility, per the user's decision.
         * Dropped first so that the two never coexist - leaving the old one in
         * place would silently keep rejecting the very case this change is
         * meant to allow.
         */
        Schema::table('subsystems', function (Blueprint $t): void {
            $t->dropUnique('subsystems_project_id_code_unique');
            $t->unique(['facility_id', 'code']);
        });

        /*
         * An area belongs to a facility, and a subsystem points at both. They
         * must agree, or a subsystem could sit in an area belonging to another
         * plant - a corruption no screen would reveal but every roll-up would
         * quietly get wrong.
         *
         * Enforced with a composite foreign key, which needs a matching unique
         * index on the parent side.
         */
        DB::unprepared('CREATE UNIQUE INDEX areas_id_facility_id_unique ON areas (id, facility_id)');
        DB::unprepared(<<<'SQL'
            ALTER TABLE subsystems
            ADD CONSTRAINT subsystems_area_within_facility
            FOREIGN KEY (area_id, facility_id)
            REFERENCES areas (id, facility_id)
            ON DELETE SET NULL
        SQL);

        // -------------------------------------------------------------------
        // RLS
        // -------------------------------------------------------------------
        foreach (['facilities', 'areas'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            // Identical clause to subsystems_tenant_isolation: readable by
            // every project participant, writable by the delivering company.
            // The breakdown is the map the whole project works from; hiding it
            // from the consultant or the MC body would break the workflow.
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
        }
    }

    public function down(): void
    {
        Schema::table('subsystems', function (Blueprint $t): void {
            $t->dropForeign('subsystems_area_within_facility');
            $t->dropForeign(['facility_id']);
            $t->dropForeign(['area_id']);
            $t->dropUnique(['facility_id', 'code']);
            $t->dropColumn(['facility_id', 'area_id']);
        });

        Schema::table('subsystems', function (Blueprint $t): void {
            $t->unique(['project_id', 'code']);
        });

        Schema::dropIfExists('areas');
        Schema::dropIfExists('facilities');
    }
};
