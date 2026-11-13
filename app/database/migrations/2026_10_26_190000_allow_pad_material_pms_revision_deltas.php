<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR-028: permit a reviewed PAD-material leaf alongside schedule deltas. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_pms_allowed_revision_delta(previous jsonb, proposed jsonb)
            RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                expected jsonb := previous;
                previous_rule jsonb;
                proposed_rule jsonb;
                previous_value jsonb;
                proposed_value jsonb;
                previous_object jsonb;
                proposed_object jsonb;
                previous_pad jsonb;
                proposed_pad jsonb;
                expected_value jsonb;
                schedule text;
                material_spec text;
                material_grade text;
                material_psl jsonb;
                material_stress jsonb;
                material_p_no jsonb;
                material_group_no jsonb;
                i integer;
            BEGIN
                IF jsonb_typeof(previous) IS DISTINCT FROM 'object'
                   OR jsonb_typeof(proposed) IS DISTINCT FROM 'object'
                   OR jsonb_typeof(previous->'rules') IS DISTINCT FROM 'array'
                   OR jsonb_typeof(proposed->'rules') IS DISTINCT FROM 'array'
                   OR jsonb_array_length(previous->'rules') <> jsonb_array_length(proposed->'rules')
                   OR jsonb_typeof(previous->'branch_rules') IS DISTINCT FROM 'array'
                   OR jsonb_typeof(proposed->'branch_rules') IS DISTINCT FROM 'array'
                   OR jsonb_array_length(previous->'branch_rules') <> jsonb_array_length(proposed->'branch_rules') THEN
                    RETURN false;
                END IF;

                -- Existing schedule rules may change only their schedule leaf.
                FOR i IN 0..jsonb_array_length(previous->'rules') - 1 LOOP
                    previous_rule := previous #> ARRAY['rules', i::text];
                    proposed_rule := proposed #> ARRAY['rules', i::text];
                    IF jsonb_typeof(previous_rule) IS DISTINCT FROM 'object'
                       OR jsonb_typeof(proposed_rule) IS DISTINCT FROM 'object' THEN
                        RETURN false;
                    END IF;
                    IF previous_rule->>'rule_kind' = 'PIPE_SCHEDULE' THEN
                        IF jsonb_typeof(previous_rule #> ARRAY['value', 'schedule']) IS DISTINCT FROM 'string'
                           OR jsonb_typeof(proposed_rule #> ARRAY['value', 'schedule']) IS DISTINCT FROM 'string' THEN
                            RETURN false;
                        END IF;
                        schedule := proposed_rule #>> ARRAY['value', 'schedule'];
                        IF schedule = '' OR schedule <> btrim(schedule) OR char_length(schedule) > 32 THEN
                            RETURN false;
                        END IF;
                        expected := jsonb_set(expected,
                            ARRAY['rules', i::text, 'value', 'schedule'], to_jsonb(schedule), false);
                    END IF;
                END LOOP;

                -- Existing PAD rules may change only value.pad_material.
                -- In the absence of that leaf the physical PAD inherits the
                -- header PIPE_MATERIAL for each applicable header DN.
                FOR i IN 0..jsonb_array_length(previous->'branch_rules') - 1 LOOP
                    previous_rule := previous #> ARRAY['branch_rules', i::text];
                    proposed_rule := proposed #> ARRAY['branch_rules', i::text];
                    IF jsonb_typeof(previous_rule) IS DISTINCT FROM 'object'
                       OR jsonb_typeof(proposed_rule) IS DISTINCT FROM 'object' THEN
                        RETURN false;
                    END IF;
                    IF previous_rule->>'reinforcement' IS DISTINCT FROM 'PAD' THEN
                        CONTINUE;
                    END IF;

                    previous_value := previous_rule->'value';
                    proposed_value := proposed_rule->'value';
                    IF previous_value IS NULL THEN previous_value := 'null'::jsonb; END IF;
                    IF proposed_value IS NULL THEN proposed_value := 'null'::jsonb; END IF;
                    IF jsonb_typeof(previous_value) NOT IN ('null', 'object')
                       OR jsonb_typeof(proposed_value) NOT IN ('null', 'object') THEN
                        RETURN false;
                    END IF;
                    previous_object := CASE WHEN jsonb_typeof(previous_value) = 'object'
                        THEN previous_value ELSE '{}'::jsonb END;
                    proposed_object := CASE WHEN jsonb_typeof(proposed_value) = 'object'
                        THEN proposed_value ELSE '{}'::jsonb END;

                    -- Prevent unrelated branch-value data from hitchhiking.
                    IF (previous_object - 'pad_material') IS DISTINCT FROM (proposed_object - 'pad_material') THEN
                        RETURN false;
                    END IF;

                    previous_pad := previous_object->'pad_material';
                    proposed_pad := proposed_object->'pad_material';
                    IF jsonb_typeof(previous_pad) = 'null' THEN previous_pad := NULL; END IF;
                    IF jsonb_typeof(proposed_pad) = 'null' THEN proposed_pad := NULL; END IF;

                    IF previous_pad IS DISTINCT FROM proposed_pad THEN
                        IF proposed_pad IS NOT NULL THEN
                            IF jsonb_typeof(proposed_pad) IS DISTINCT FROM 'object' THEN
                                RETURN false;
                            END IF;
                            material_spec := proposed_pad->>'spec';
                            material_grade := proposed_pad->>'grade';
                            IF jsonb_typeof(proposed_pad->'spec') IS DISTINCT FROM 'string'
                               OR material_spec IS NULL OR btrim(material_spec) = '' OR char_length(material_spec) > 80
                               OR jsonb_typeof(proposed_pad->'grade') IS DISTINCT FROM 'string'
                               OR material_grade IS NULL OR btrim(material_grade) = '' OR char_length(material_grade) > 80 THEN
                                RETURN false;
                            END IF;

                            material_psl := proposed_pad->'psl';
                            IF material_psl IS NOT NULL AND jsonb_typeof(material_psl) <> 'null'
                               AND (jsonb_typeof(material_psl) <> 'string'
                                    OR btrim(proposed_pad->>'psl') = ''
                                    OR char_length(proposed_pad->>'psl') > 16) THEN
                                RETURN false;
                            END IF;

                            material_stress := proposed_pad->'s_mpa_override';
                            IF material_stress IS NOT NULL AND jsonb_typeof(material_stress) <> 'null' THEN
                                IF jsonb_typeof(material_stress) <> 'number'
                                   OR (proposed_pad->>'s_mpa_override')::numeric <= 0 THEN
                                    RETURN false;
                                END IF;
                                material_p_no := proposed_pad->'p_no';
                                material_group_no := proposed_pad->'group_no';
                                IF jsonb_typeof(material_p_no) IS DISTINCT FROM 'string'
                                   OR (proposed_pad->>'p_no') !~ '^[1-9][0-9]*([A-Z]{1,2})?$'
                                   OR (jsonb_typeof(material_group_no) IS DISTINCT FROM 'number'
                                       AND jsonb_typeof(material_group_no) IS DISTINCT FROM 'string')
                                   OR (proposed_pad->>'group_no') !~ '^[1-9][0-9]*$' THEN
                                    RETURN false;
                                END IF;
                            END IF;

                            expected_value := jsonb_set(previous_object, '{pad_material}', proposed_pad, true);
                        ELSE
                            expected_value := previous_object - 'pad_material';
                            IF expected_value = '{}'::jsonb THEN
                                expected_value := 'null'::jsonb;
                            END IF;
                        END IF;
                        expected := jsonb_set(expected,
                            ARRAY['branch_rules', i::text, 'value'], expected_value, true);
                    END IF;
                END LOOP;

                RETURN expected = proposed;
            END $$;
        SQL);

        $this->replaceSnapshotGuardCall('epcc_pms_schedule_only_delta', 'epcc_pms_allowed_revision_delta');
    }

    public function down(): void
    {
        $this->replaceSnapshotGuardCall('epcc_pms_allowed_revision_delta', 'epcc_pms_schedule_only_delta');
        DB::statement('DROP FUNCTION IF EXISTS epcc_pms_allowed_revision_delta(jsonb, jsonb)');
    }

    private function replaceSnapshotGuardCall(string $from, string $to): void
    {
        $row = DB::selectOne("SELECT pg_get_functiondef('epcc_check_pms_snapshot()'::regprocedure) AS definition");
        $definition = (string) ($row->definition ?? '');
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException("Expected exactly one {$from} call in epcc_check_pms_snapshot().");
        }

        DB::unprepared(str_replace($from, $to, $definition));
    }
};
