<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Preserve old issued rows but refuse NEW AFC/release transitions without PMS provenance. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_require_pin_on_line_issue()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.release_state IS DISTINCT FROM OLD.release_state
                   AND NEW.release_state IN ('AFC_CONFIRMED', 'RELEASED')
                   AND (NEW.piping_class_id IS NULL OR NEW.pms_class_snapshot_id IS NULL) THEN
                    RAISE EXCEPTION 'LINE_ISSUE_REQUIRES_PMS_PIN'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_lines_issue_requires_pms_pin
                BEFORE UPDATE OF release_state ON piping_lines
                FOR EACH ROW EXECUTE FUNCTION epcc_require_pin_on_line_issue();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS piping_lines_issue_requires_pms_pin ON piping_lines');
        DB::statement('DROP FUNCTION IF EXISTS epcc_require_pin_on_line_issue()');
    }
};
