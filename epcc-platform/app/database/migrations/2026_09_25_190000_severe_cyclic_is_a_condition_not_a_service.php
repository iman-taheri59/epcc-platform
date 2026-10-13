<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ASME B31.3-2024 clause 300.2 — a CONDITION is not a SERVICE.
 *
 * Step 4 gave `piping_classes` a `fluid_service_category` with five values
 * taken from industry habit: NORMAL, CATEGORY_D, CATEGORY_M, HIGH_PRESSURE and
 * SEVERE_CYCLIC. Reading the Code shows two things wrong with that list.
 *
 * FIRST, SEVERE_CYCLIC IS NOT A FLUID SERVICE. Clause 300.2 defines "severe
 * cyclic conditions" separately, as *"conditions applying to specific piping
 * components or joints for which the owner or the designer determines that
 * design and construction to better resist fatigue loading is warranted"*. It
 * is a property the owner attaches to a line, on top of whatever fluid service
 * that line already has. Holding it as a sixth value of one enum makes
 * "Category M under severe cyclic conditions" unrecordable — and that is an
 * ordinary line in a real plant, not an exotic one.
 *
 * SECOND, TWO REAL SERVICES WERE MISSING: Elevated Temperature (300.2(c)) and
 * High Purity (300.2(e)). A class in either could not be stated at all.
 *
 * So the column keeps the six services the Code lists and loses the condition,
 * which moves to a boolean of its own. Any row already carrying SEVERE_CYCLIC
 * is migrated rather than rejected: the fluid service becomes NULL — because
 * the sheet never said what it was — and the condition flag is set, which is
 * exactly what the sheet did say.
 */
return new class extends Migration
{
    /** The six B31.3-2024 fluid services. */
    private const SERVICES = [
        'NORMAL',
        'CATEGORY_D',
        'CATEGORY_M',
        'ELEVATED_TEMPERATURE',
        'HIGH_PRESSURE',
        'HIGH_PURITY',
    ];

    public function up(): void
    {
        Schema::table('piping_classes', function (Blueprint $t): void {
            // Not nullable: a line either is under severe cyclic conditions or
            // it is not, and "unknown" is the same as "not designated" here -
            // the designation is an affirmative act by the owner or designer.
            $t->boolean('severe_cyclic_conditions')->default(false);
        });

        DB::statement('ALTER TABLE piping_classes
            DROP CONSTRAINT IF EXISTS piping_classes_fluid_service_category_check');

        // Carry the condition across before the value becomes illegal.
        DB::statement("UPDATE piping_classes
                       SET severe_cyclic_conditions = true,
                           fluid_service_category = NULL
                       WHERE fluid_service_category = 'SEVERE_CYCLIC'");

        $list = "'".implode("','", self::SERVICES)."'";
        DB::statement("ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_fluid_service_category_check
            CHECK (fluid_service_category IS NULL
                   OR fluid_service_category IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE piping_classes
            DROP CONSTRAINT IF EXISTS piping_classes_fluid_service_category_check');

        DB::statement("UPDATE piping_classes
                       SET fluid_service_category = 'SEVERE_CYCLIC'
                       WHERE severe_cyclic_conditions = true
                         AND fluid_service_category IS NULL");

        // The two services B31.3 has and the old list did not cannot survive a
        // rollback; there is nowhere in the old enum to put them.
        DB::statement("UPDATE piping_classes SET fluid_service_category = NULL
                       WHERE fluid_service_category IN ('ELEVATED_TEMPERATURE','HIGH_PURITY')");

        DB::statement("ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_fluid_service_category_check
            CHECK (fluid_service_category IS NULL
                   OR fluid_service_category IN
                      ('NORMAL','CATEGORY_D','CATEGORY_M','HIGH_PRESSURE','SEVERE_CYCLIC'))");

        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->dropColumn('severe_cyclic_conditions');
        });
    }
};
