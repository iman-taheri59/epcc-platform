<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR-028: allow only schedule-only working copies to become immutable snapshots. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_pms_schedule_only_delta(previous jsonb, proposed jsonb)
            RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                expected jsonb := previous;
                i integer;
                schedule text;
            BEGIN
                IF jsonb_typeof(previous->'rules') IS DISTINCT FROM 'array'
                   OR jsonb_typeof(proposed->'rules') IS DISTINCT FROM 'array'
                   OR jsonb_array_length(previous->'rules') <> jsonb_array_length(proposed->'rules') THEN
                    RETURN false;
                END IF;
                FOR i IN 0..jsonb_array_length(previous->'rules') - 1 LOOP
                    IF previous #>> ARRAY['rules', i::text, 'rule_kind'] = 'PIPE_SCHEDULE' THEN
                        IF jsonb_typeof(previous #> ARRAY['rules', i::text, 'value', 'schedule']) IS DISTINCT FROM 'string'
                           OR jsonb_typeof(proposed #> ARRAY['rules', i::text, 'value', 'schedule']) IS DISTINCT FROM 'string' THEN
                            RETURN false;
                        END IF;
                        schedule := proposed #>> ARRAY['rules', i::text, 'value', 'schedule'];
                        IF schedule = '' OR schedule <> btrim(schedule) OR char_length(schedule) > 32 THEN
                            RETURN false;
                        END IF;
                        expected := jsonb_set(expected, ARRAY['rules', i::text, 'value', 'schedule'], to_jsonb(schedule), false);
                    END IF;
                END LOOP;
                RETURN expected = proposed;
            END $$;

            CREATE OR REPLACE FUNCTION epcc_check_pms_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                live_spec jsonb;
                prior_spec jsonb;
                draft_spec jsonb;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_IMMUTABLE' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM piping_classes c
                    JOIN documents d ON d.id = c.pms_document_id
                    JOIN document_revisions r ON r.document_id = d.id
                    WHERE c.id = NEW.piping_class_id AND c.project_id = NEW.project_id
                      AND c.company_id = NEW.company_id AND c.state = 'PUBLISHED'
                      AND d.id = NEW.pms_document_id AND d.project_id = NEW.project_id
                      AND d.current_revision_id = NEW.document_revision_id
                      AND d.doc_type = 'PMS' AND d.discipline = 'PIPING'
                      AND r.id = NEW.document_revision_id AND r.project_id = NEW.project_id
                      AND r.cde_state = 'PUBLISHED' AND r.suitability LIKE 'A%'
                ) THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_SOURCE_INVALID' USING ERRCODE = '23514';
                END IF;
                SELECT jsonb_build_object(
                    'class', to_jsonb(c),
                    'rules', COALESCE((SELECT jsonb_agg(to_jsonb(rr) ORDER BY rr.id)
                        FROM piping_class_rules rr WHERE rr.piping_class_id = c.id), '[]'::jsonb),
                    'branch_rules', COALESCE((SELECT jsonb_agg(to_jsonb(br) ORDER BY br.id)
                        FROM piping_class_branch_rules br WHERE br.piping_class_id = c.id), '[]'::jsonb)
                ) INTO live_spec FROM piping_classes c WHERE c.id = NEW.piping_class_id;
                SELECT s.specification INTO prior_spec
                FROM pms_class_revision_snapshots s
                JOIN document_revisions old_rev ON old_rev.id = s.document_revision_id
                JOIN document_revisions new_rev ON new_rev.id = NEW.document_revision_id
                WHERE s.pms_document_id = NEW.pms_document_id
                  AND s.piping_class_id = NEW.piping_class_id
                  AND old_rev.ordinal = new_rev.ordinal - 1
                  AND old_rev.cde_state IN ('PUBLISHED', 'ARCHIVED')
                  AND old_rev.suitability LIKE 'A%';
                SELECT specification INTO draft_spec FROM pms_class_revision_drafts
                WHERE document_revision_id = NEW.document_revision_id
                  AND pms_document_id = NEW.pms_document_id
                  AND piping_class_id = NEW.piping_class_id
                  AND project_id = NEW.project_id AND company_id = NEW.company_id;
                IF draft_spec IS NOT NULL THEN
                    IF prior_spec IS NULL OR draft_spec IS DISTINCT FROM NEW.specification
                       OR epcc_pms_schedule_only_delta(prior_spec, NEW.specification) IS DISTINCT FROM true THEN
                        RAISE EXCEPTION 'PMS_SNAPSHOT_CONTENT_MISMATCH' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.specification IS DISTINCT FROM live_spec THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_CONTENT_MISMATCH' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
        SQL);
    }

    public function down(): void
    {
        // Restore the original live-class-only snapshot guard, preserving
        // issued rows (a rollback does not erase already issued snapshots).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_check_pms_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_IMMUTABLE' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM piping_classes c JOIN documents d ON d.id = c.pms_document_id
                    JOIN document_revisions r ON r.document_id = d.id
                    WHERE c.id = NEW.piping_class_id AND c.project_id = NEW.project_id
                      AND c.company_id = NEW.company_id AND c.state = 'PUBLISHED'
                      AND d.id = NEW.pms_document_id AND d.project_id = NEW.project_id
                      AND d.doc_type = 'PMS' AND d.discipline = 'PIPING'
                      AND r.id = NEW.document_revision_id AND r.project_id = NEW.project_id
                      AND r.cde_state = 'PUBLISHED' AND r.suitability LIKE 'A%'
                ) THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_SOURCE_INVALID' USING ERRCODE = '23514';
                END IF;
                IF NEW.specification IS DISTINCT FROM (
                    SELECT jsonb_build_object('class', to_jsonb(c),
                        'rules', COALESCE((SELECT jsonb_agg(to_jsonb(rr) ORDER BY rr.id)
                            FROM piping_class_rules rr WHERE rr.piping_class_id = c.id), '[]'::jsonb),
                        'branch_rules', COALESCE((SELECT jsonb_agg(to_jsonb(br) ORDER BY br.id)
                            FROM piping_class_branch_rules br WHERE br.piping_class_id = c.id), '[]'::jsonb)
                    ) FROM piping_classes c WHERE c.id = NEW.piping_class_id
                ) THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_CONTENT_MISMATCH' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            DROP FUNCTION epcc_pms_schedule_only_delta(jsonb, jsonb);
        SQL);
    }
};
