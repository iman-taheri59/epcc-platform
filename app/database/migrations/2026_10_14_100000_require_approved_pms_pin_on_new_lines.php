<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR-028: deny unclassified NEW lines while leaving historical rows intact. */
return new class extends Migration
{
    public function up(): void
    {
        // A column-wide NOT NULL would retroactively reject historical rows
        // still awaiting owner-reviewed classification. An INSERT-only trigger
        // blocks every new writer (including raw SQL/bulk) without inventing a
        // class for an old line. The existing pin guard validates non-null
        // references, current A-family PMS approval, project and class.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_require_new_line_pms_pin() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.piping_class_id IS NULL OR NEW.pms_class_snapshot_id IS NULL THEN
                    RAISE EXCEPTION 'LINE_REQUIRES_APPROVED_PMS_CLASS'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_lines_require_pms_pin
                BEFORE INSERT ON piping_lines
                FOR EACH ROW EXECUTE FUNCTION epcc_require_new_line_pms_pin();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS piping_lines_require_pms_pin ON piping_lines');
        DB::statement('DROP FUNCTION IF EXISTS epcc_require_new_line_pms_pin()');
    }
};
