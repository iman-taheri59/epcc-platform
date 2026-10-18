<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR-028: raw SQL cannot approve a PMS document with no class snapshots. */
return new class extends Migration
{
    public function up(): void
    {
        // Deferred until transaction commit: the CDE service first updates
        // the revision, THEN captures immutable snapshots in the same TX.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_require_pms_issue_snapshots()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                governing_document uuid;
                class_count integer;
                snapshot_count integer;
            BEGIN
                SELECT d.id INTO governing_document FROM documents d
                JOIN document_revisions r ON r.id = d.current_revision_id
                WHERE d.id = NEW.document_id AND r.id = NEW.id
                  AND d.doc_type = 'PMS' AND d.discipline = 'PIPING'
                  AND r.cde_state = 'PUBLISHED' AND r.suitability LIKE 'A%';
                IF governing_document IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT count(*) INTO class_count FROM piping_classes c
                WHERE c.pms_document_id = governing_document
                  AND c.project_id = NEW.project_id AND c.state = 'PUBLISHED';
                IF class_count = 0 OR EXISTS (
                    SELECT 1 FROM piping_classes c
                    WHERE c.pms_document_id = governing_document
                      AND (c.project_id <> NEW.project_id OR c.state <> 'PUBLISHED')
                ) THEN
                    RAISE EXCEPTION 'PMS_ISSUE_NEEDS_PUBLISHED_CLASSES' USING ERRCODE = '23514';
                END IF;
                SELECT count(*) INTO snapshot_count FROM pms_class_revision_snapshots s
                WHERE s.document_revision_id = NEW.id AND s.pms_document_id = governing_document
                  AND s.project_id = NEW.project_id;
                IF snapshot_count <> class_count THEN
                    RAISE EXCEPTION 'PMS_ISSUE_NEEDS_CLASS_SNAPSHOTS' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER pms_approved_revision_snapshots_required
                AFTER INSERT OR UPDATE ON document_revisions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION epcc_require_pms_issue_snapshots();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS pms_approved_revision_snapshots_required ON document_revisions');
        DB::statement('DROP FUNCTION IF EXISTS epcc_require_pms_issue_snapshots()');
    }
};
