<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-014 §14: preserve original decimals; never backfill unknown source pairs. */
return new class extends Migration
{
    private const FIELDS = [
        'design_temp_min' => ['design_temp_min_c', -273.15, 9999.9],
        'design_temp_max' => ['design_temp_max_c', -273.15, 9999.9],
        'corrosion_allowance' => ['corrosion_allowance_mm', 0, 999.99],
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $field => [$canonical, $minimum, $maximum]) {
            Schema::table('piping_classes', function (Blueprint $table) use ($field): void {
                $table->string($field.'_value', 32)->nullable();
                $table->string($field.'_unit', 16)->nullable();
            });
            DB::statement("ALTER TABLE piping_classes ALTER COLUMN {$canonical} TYPE numeric(16,6)");
            DB::statement("ALTER TABLE piping_classes ADD CONSTRAINT pms_{$field}_bounds CHECK ({$canonical} IS NULL OR ({$canonical} >= {$minimum} AND {$canonical} <= {$maximum}))");
            DB::statement("ALTER TABLE piping_classes ADD CONSTRAINT pms_{$field}_pair CHECK (
                ({$field}_value IS NULL AND {$field}_unit IS NULL)
                OR ({$field}_value IS NOT NULL AND {$field}_unit IS NOT NULL AND {$canonical} IS NOT NULL
                    AND {$field}_value ~ '^[+-]?([0-9]{1,10}(\\.[0-9]{1,6})?|\\.[0-9]{1,6})$'
                    AND length(btrim({$field}_unit)) > 0 AND {$field}_unit = btrim({$field}_unit)))");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_guard_pms_measurement_mirrors() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE field text; mirror text; previous jsonb; incoming jsonb;
            BEGIN
                previous := to_jsonb(OLD);
                incoming := to_jsonb(NEW);
                FOREACH field IN ARRAY ARRAY['design_temp_min', 'design_temp_max', 'corrosion_allowance'] LOOP
                    mirror := CASE WHEN field = 'corrosion_allowance' THEN field || '_mm' ELSE field || '_c' END;
                    IF previous->>(field || '_value') IS NOT NULL THEN
                        IF incoming->>(field || '_value') IS NULL AND incoming->>mirror IS NOT NULL THEN
                            RAISE EXCEPTION 'PMS_UNITS_SOURCE_PAIR_REQUIRED' USING ERRCODE = '23514';
                        END IF;
                        IF incoming->mirror IS DISTINCT FROM previous->mirror
                            AND incoming->(field || '_value') IS NOT DISTINCT FROM previous->(field || '_value')
                            AND incoming->(field || '_unit') IS NOT DISTINCT FROM previous->(field || '_unit') THEN
                            RAISE EXCEPTION 'PMS_UNITS_MIRROR_ONLY_UPDATE' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END LOOP;
                RETURN NEW;
            END $$;
            CREATE TRIGGER pms_measurement_mirrors_guard BEFORE UPDATE ON piping_classes
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_pms_measurement_mirrors();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: reverting would discard PMS entered units and canonical precision.');
    }
};
