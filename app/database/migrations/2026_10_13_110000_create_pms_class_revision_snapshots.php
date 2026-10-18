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
        Schema::create('pms_class_revision_snapshots', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('pms_document_id');
            $t->uuid('document_revision_id');
            $t->uuid('piping_class_id');
            $t->jsonb('specification');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('pms_document_id')->references('id')->on('documents')->restrictOnDelete();
            $t->foreign('document_revision_id')->references('id')->on('document_revisions')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->restrictOnDelete();
            $t->unique(['document_revision_id', 'piping_class_id'], 'pms_snapshot_revision_class_unique');
            $t->index(['project_id', 'piping_class_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_check_pms_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
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
                      AND d.doc_type = 'PMS' AND d.discipline = 'PIPING'
                      AND r.id = NEW.document_revision_id AND r.project_id = NEW.project_id
                      AND r.cde_state = 'PUBLISHED' AND r.suitability LIKE 'A%'
                ) THEN
                    RAISE EXCEPTION 'PMS_SNAPSHOT_SOURCE_INVALID' USING ERRCODE = '23514';
                END IF;
                IF NEW.specification IS DISTINCT FROM (
                    SELECT jsonb_build_object(
                        'class', to_jsonb(c),
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
            CREATE TRIGGER pms_snapshot_guard BEFORE INSERT OR UPDATE OR DELETE
                ON pms_class_revision_snapshots FOR EACH ROW
                EXECUTE FUNCTION epcc_check_pms_snapshot();
        SQL);

        $c = "NULLIF(current_setting('epcc.company_id', true), '')::uuid";
        $p = "NULLIF(current_setting('epcc.project_id', true), '')";
        DB::statement('ALTER TABLE pms_class_revision_snapshots ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pms_class_revision_snapshots FORCE ROW LEVEL SECURITY');
        DB::statement(<<<SQL
            CREATE POLICY pms_snapshot_tenant_isolation ON pms_class_revision_snapshots
            USING (
                project_id IN (
                    SELECT pc.project_id FROM project_company pc
                    WHERE pc.company_id = {$c} AND ({$p} IS NULL OR pc.project_id = {$p}::uuid)
                    UNION
                    SELECT pr.id FROM projects pr WHERE pr.owner_company_id = {$c}
                      AND ({$p} IS NULL OR pr.id = {$p}::uuid)
                )
                AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = pms_class_revision_snapshots.project_id AND pc.company_id = {$c})
            )
            WITH CHECK (
                company_id = {$c}
                AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = pms_class_revision_snapshots.project_id AND pc.company_id = {$c})
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pms_class_revision_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS epcc_check_pms_snapshot()');
    }
};
