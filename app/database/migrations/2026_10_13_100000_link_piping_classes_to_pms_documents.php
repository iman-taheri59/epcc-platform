<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-028: stable class identity belongs to at most one PMS document. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('piping_classes', function (Blueprint $table): void {
            $table->uuid('pms_document_id')->nullable();
            $table->foreign('pms_document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->index(['project_id', 'pms_document_id']);
        });

        // A FK alone would allow a drawing or a document from another project.
        // A class may be attached once, never silently moved to another PMS.
        // This trigger validates relational identity only; it is NOT the PMS
        // author-policy backstop or a class-revision snapshot.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_check_pms_class_document() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.pms_document_id IS NOT NULL
                    AND NEW.pms_document_id IS DISTINCT FROM OLD.pms_document_id THEN
                    RAISE EXCEPTION 'PMS_CLASS_DOCUMENT_IMMUTABLE' USING ERRCODE = '23514';
                END IF;
                IF NEW.pms_document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM documents d
                    JOIN document_revisions r ON r.id = d.current_revision_id
                    WHERE d.id = NEW.pms_document_id AND d.project_id = NEW.project_id
                      AND r.document_id = d.id AND r.project_id = d.project_id
                      AND r.cde_state = 'WIP' AND d.discipline = 'PIPING' AND d.doc_type = 'PMS'
                ) THEN
                    RAISE EXCEPTION 'PMS_CLASS_DOCUMENT_INVALID' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_classes_pms_document_guard
            BEFORE INSERT OR UPDATE OF pms_document_id, project_id ON piping_classes
            FOR EACH ROW EXECUTE FUNCTION epcc_check_pms_class_document();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS piping_classes_pms_document_guard ON piping_classes');
        Schema::table('piping_classes', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'pms_document_id']);
            $table->dropForeign(['pms_document_id']);
            $table->dropColumn('pms_document_id');
        });
        DB::statement('DROP FUNCTION IF EXISTS epcc_check_pms_class_document()');
    }
};
