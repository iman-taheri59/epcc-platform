<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('piping_lines', function (Blueprint $t): void {
            // Nullable until the legacy register and every import writer have
            // been migrated to the strict creation gate. Never backfill with
            // an invented class/revision (ADR-028).
            $t->uuid('pms_class_snapshot_id')->nullable();
            $t->foreign('pms_class_snapshot_id', 'piping_lines_pms_snapshot_fk')
                ->references('id')->on('pms_class_revision_snapshots')->restrictOnDelete();
            $t->index('pms_class_snapshot_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_check_line_pms_pin() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.pms_class_snapshot_id IS NOT NULL
                   AND NEW.pms_class_snapshot_id IS DISTINCT FROM OLD.pms_class_snapshot_id THEN
                    RAISE EXCEPTION 'LINE_PMS_PIN_IMMUTABLE' USING ERRCODE = '23514';
                END IF;
                -- Historical lines keep their issue-time revision even after
                -- it is superseded. New pins alone require current approval.
                IF NEW.pms_class_snapshot_id IS NOT NULL THEN
                    IF NEW.piping_class_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM pms_class_revision_snapshots s
                        WHERE s.id = NEW.pms_class_snapshot_id
                          AND s.project_id = NEW.project_id
                          AND s.piping_class_id = NEW.piping_class_id
                    ) THEN
                        RAISE EXCEPTION 'LINE_PMS_PIN_MISMATCH' USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'INSERT' OR OLD.pms_class_snapshot_id IS NULL THEN
                        IF NOT EXISTS (
                            SELECT 1 FROM pms_class_revision_snapshots s
                            JOIN documents d ON d.id = s.pms_document_id
                            JOIN document_revisions r ON r.id = s.document_revision_id
                            JOIN piping_classes c ON c.id = s.piping_class_id
                            WHERE s.id = NEW.pms_class_snapshot_id
                              AND d.current_revision_id = r.id
                              AND r.cde_state = 'PUBLISHED' AND r.suitability LIKE 'A%'
                              AND c.state = 'PUBLISHED' AND c.project_id = NEW.project_id
                        ) THEN
                            RAISE EXCEPTION 'LINE_PMS_PIN_NOT_CURRENT' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_lines_pms_pin_guard
                BEFORE INSERT OR UPDATE OF pms_class_snapshot_id, piping_class_id, project_id
                ON piping_lines FOR EACH ROW EXECUTE FUNCTION epcc_check_line_pms_pin();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS piping_lines_pms_pin_guard ON piping_lines');
        Schema::table('piping_lines', function (Blueprint $t): void {
            $t->dropIndex(['pms_class_snapshot_id']);
            $t->dropForeign('piping_lines_pms_snapshot_fk');
            $t->dropColumn('pms_class_snapshot_id');
        });
        DB::statement('DROP FUNCTION IF EXISTS epcc_check_line_pms_pin()');
    }
};
