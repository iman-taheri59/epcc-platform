<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-008 Step B (requirement P-24, owner decisions 2026-10-12):
 *
 * 1. Every piping class carries its own DESIGN BASIS — design pressure and
 *    design temperature are class attributes, not line attributes (the
 *    per-line columns on piping_lines stay for line-level checks). Both are
 *    nullable while a class is drafted; the publish gate (service layer)
 *    refuses to publish a class without both. The CHECKs here only police
 *    physical sanity, NULL-explicit because a CHECK that evaluates to NULL
 *    passes (lesson from the P1 migration).
 *
 * 2. The PIPE_MATERIAL rule kind: the pipe material grade per DN band, whose
 *    value resolves against the ASME B31.3-2024 materials reference pack
 *    (standards-packs/reference/b313-materials.ref.json). Adding it means
 *    re-stating the kind CHECK, which is a closed list.
 *
 * Units: pressure in MPa, temperature in degrees Celsius — the platform's
 * engineering units (ADR-014), the units of B31.3 Table A-1 (SI) and of the
 * branch-reinforcement calculator.
 */
return new class extends Migration
{
    /** The closed kind list after this migration. */
    private const RULE_KINDS = [
        'PIPE_SCHEDULE', 'END_PREPARATION', 'VALVE_TYPE', 'FLANGE_TYPE',
        'GASKET_TYPE', 'BOLT_SPEC', 'CORROSION_ALLOWANCE', 'PIPE_MATERIAL',
    ];

    /** The kind list as the P1 migration created it (for rollback). */
    private const RULE_KINDS_BEFORE = [
        'PIPE_SCHEDULE', 'END_PREPARATION', 'VALVE_TYPE', 'FLANGE_TYPE',
        'GASKET_TYPE', 'BOLT_SPEC', 'CORROSION_ALLOWANCE',
    ];

    public function up(): void
    {
        Schema::table('piping_classes', function (Blueprint $t): void {
            // Design pressure, MPa; design temperature, degrees Celsius.
            $t->decimal('design_pressure_mpa', 10, 4)->nullable();
            $t->decimal('design_temperature_c', 6, 2)->nullable();
        });

        $kinds = implode(', ', array_map(static fn (string $k) => "'{$k}'", self::RULE_KINDS));

        DB::unprepared(<<<SQL
            ALTER TABLE piping_classes ADD CONSTRAINT piping_classes_design_pressure_sane
                CHECK (design_pressure_mpa IS NULL OR design_pressure_mpa >= 0);
            ALTER TABLE piping_classes ADD CONSTRAINT piping_classes_design_temperature_sane
                CHECK (design_temperature_c IS NULL
                       OR design_temperature_c BETWEEN -273.15 AND 1100);

            ALTER TABLE piping_class_rules DROP CONSTRAINT piping_class_rules_kind_valid;
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_kind_valid
                CHECK (rule_kind IN ({$kinds}));
        SQL);
    }

    public function down(): void
    {
        $kinds = implode(', ', array_map(static fn (string $k) => "'{$k}'", self::RULE_KINDS_BEFORE));

        DB::unprepared(<<<SQL
            ALTER TABLE piping_class_rules DROP CONSTRAINT piping_class_rules_kind_valid;
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_kind_valid
                CHECK (rule_kind IN ({$kinds}));

            ALTER TABLE piping_classes DROP CONSTRAINT piping_classes_design_pressure_sane;
            ALTER TABLE piping_classes DROP CONSTRAINT piping_classes_design_temperature_sane;
        SQL);

        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->dropColumn(['design_pressure_mpa', 'design_temperature_c']);
        });
    }
};
