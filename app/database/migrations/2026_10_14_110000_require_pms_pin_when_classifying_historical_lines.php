<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR-028: historical records can remain unmapped, but not acquire a bare class. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_require_pin_on_historical_class_change()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.pms_class_snapshot_id IS NULL
                   AND NEW.piping_class_id IS DISTINCT FROM OLD.piping_class_id
                   AND NEW.piping_class_id IS NOT NULL
                   AND NEW.pms_class_snapshot_id IS NULL THEN
                    RAISE EXCEPTION 'LINE_CLASS_CHANGE_REQUIRES_PMS_PIN'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_lines_historical_class_guard
                BEFORE UPDATE OF piping_class_id ON piping_lines
                FOR EACH ROW EXECUTE FUNCTION epcc_require_pin_on_historical_class_change();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS piping_lines_historical_class_guard ON piping_lines');
        DB::statement('DROP FUNCTION IF EXISTS epcc_require_pin_on_historical_class_change()');
    }
};
