<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR-028: the live specification cannot drift behind an approved snapshot. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_freeze_published_piping_class()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.state = 'PUBLISHED' THEN
                    RAISE EXCEPTION 'PUBLISHED_PIPING_CLASS_IMMUTABLE'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_classes_published_freeze
                BEFORE UPDATE OR DELETE ON piping_classes
                FOR EACH ROW EXECUTE FUNCTION epcc_freeze_published_piping_class();

            CREATE OR REPLACE FUNCTION epcc_freeze_published_piping_rule()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Lock parent while checking its state; serialize with publish
                -- to prevent a simultaneous rule edit from escaping the gate.
                IF TG_OP <> 'INSERT' AND EXISTS (
                    SELECT 1 FROM piping_classes WHERE id = OLD.piping_class_id
                    AND state = 'PUBLISHED' FOR SHARE
                ) THEN
                    RAISE EXCEPTION 'PUBLISHED_PIPING_RULE_IMMUTABLE'
                        USING ERRCODE = '23514';
                END IF;
                IF TG_OP <> 'DELETE' AND EXISTS (
                    SELECT 1 FROM piping_classes WHERE id = NEW.piping_class_id
                    AND state = 'PUBLISHED' FOR SHARE
                ) THEN
                    RAISE EXCEPTION 'PUBLISHED_PIPING_RULE_IMMUTABLE'
                        USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER piping_class_rules_published_freeze
                BEFORE INSERT OR UPDATE OR DELETE ON piping_class_rules
                FOR EACH ROW EXECUTE FUNCTION epcc_freeze_published_piping_rule();
            CREATE TRIGGER piping_class_branch_rules_published_freeze
                BEFORE INSERT OR UPDATE OR DELETE ON piping_class_branch_rules
                FOR EACH ROW EXECUTE FUNCTION epcc_freeze_published_piping_rule();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS piping_class_rules_published_freeze ON piping_class_rules');
        DB::statement('DROP TRIGGER IF EXISTS piping_class_branch_rules_published_freeze ON piping_class_branch_rules');
        DB::statement('DROP FUNCTION IF EXISTS epcc_freeze_published_piping_rule()');
        DB::statement('DROP TRIGGER IF EXISTS piping_classes_published_freeze ON piping_classes');
        DB::statement('DROP FUNCTION IF EXISTS epcc_freeze_published_piping_class()');
    }
};
