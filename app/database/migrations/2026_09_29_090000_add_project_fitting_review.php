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
        Schema::table('project_fitting_definitions', function (Blueprint $t): void {
            $t->uuid('revises_id')->nullable()->unique();
            $t->uuid('root_id')->nullable()->index();
            $t->text('correction_reason')->nullable();
            $t->unique(['id','project_id','company_id'], 'fitting_definition_scope_unique');
            $t->foreign(['revises_id','project_id','company_id'], 'fitting_revision_scope_fk')
                ->references(['id','project_id','company_id'])->on('project_fitting_definitions')->restrictOnDelete();
            $t->foreign(['root_id','project_id','company_id'], 'fitting_root_scope_fk')
                ->references(['id','project_id','company_id'])->on('project_fitting_definitions')->restrictOnDelete();
            $t->dropUnique('project_fitting_source_unique');
        });
        DB::statement("CREATE UNIQUE INDEX project_fitting_source_unique ON project_fitting_definitions
            (project_id,company_id,component_type,qualifier,nps,spec_number,spec_revision,spec_clause) WHERE revises_id IS NULL");
        DB::statement("ALTER TABLE project_fitting_definitions ADD CONSTRAINT fitting_revision_reason CHECK (
            (revises_id IS NULL AND root_id IS NULL AND correction_reason IS NULL)
            OR (revises_id IS NOT NULL AND root_id IS NOT NULL AND revises_id <> id AND root_id <> id
                AND correction_reason IS NOT NULL AND length(trim(correction_reason)) > 0))");

        Schema::create('fitting_review_policies', function (Blueprint $t): void {
            $this->identity($t);
            $t->integer('version');
            $t->jsonb('steps');
            $t->unique(['project_id','version']);
            $t->unique(['id','project_id']);
        });
        DB::statement("ALTER TABLE fitting_review_policies ADD CONSTRAINT fitting_policy_shape CHECK
            (version > 0 AND jsonb_typeof(steps) = 'array' AND jsonb_array_length(steps) BETWEEN 1 AND 5)");
        Schema::create('fitting_review_runs', function (Blueprint $t): void {
            $this->identity($t);
            $t->uuid('definition_id')->unique();
            $t->uuid('policy_id');
            $t->foreign(['definition_id','project_id','company_id'], 'fitting_run_definition_fk')
                ->references(['id','project_id','company_id'])->on('project_fitting_definitions')->restrictOnDelete();
            $t->foreign(['policy_id','project_id'], 'fitting_run_policy_fk')
                ->references(['id','project_id'])->on('fitting_review_policies')->restrictOnDelete();
            $t->unique(['id','project_id']);
        });
        Schema::create('fitting_review_decisions', function (Blueprint $t): void {
            $this->identity($t);
            $t->uuid('run_id');
            $t->integer('stage');
            $t->string('decision', 16);
            $t->text('comment');
            $t->string('actor_name');
            $t->foreign(['run_id','project_id'], 'fitting_decision_run_fk')
                ->references(['id','project_id'])->on('fitting_review_runs')->restrictOnDelete();
            $t->unique(['run_id','stage']);
            $t->unique(['run_id','created_by']);
        });
        DB::statement("ALTER TABLE fitting_review_decisions ADD CONSTRAINT fitting_decision_shape CHECK
            (stage BETWEEN 1 AND 5 AND decision IN ('APPROVE','RETURN') AND length(trim(comment)) > 0)");
        foreach (['fitting_review_policies','fitting_review_runs','fitting_review_decisions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            $visible = "({$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = {$table}.project_id
                    AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid))";
            $owner = $table === 'fitting_review_policies'
                ? "AND EXISTS (SELECT 1 FROM projects p WHERE p.id = {$table}.project_id AND p.owner_company_id = {$table}.company_id)" : '';
            DB::statement("CREATE POLICY {$table}_read ON {$table} FOR SELECT USING ({$visible})");
            DB::statement("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK ({$visible}
                AND {$table}.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND epcc_project_is_writable({$table}.project_id) {$owner}
                AND EXISTS (SELECT 1 FROM users u JOIN project_memberships pm ON pm.user_id=u.id
                    WHERE u.id={$table}.created_by AND u.company_id={$table}.company_id AND u.is_active
                    AND pm.project_id={$table}.project_id))");
            // Immutable policies, snapshots and decisions: no UPDATE/DELETE policy.
        }
        $this->decisionGuard();
    }

    private function decisionGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_guard_fitting_decision() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE definition_row record; workflow_steps jsonb; selector text; signer_roles jsonb;
                    signer_scope jsonb; already integer;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('fitting-review:' || NEW.project_id::text, 0));
                SELECT d.* INTO definition_row FROM fitting_review_runs r
                    JOIN project_fitting_definitions d ON d.id=r.definition_id
                    WHERE r.id=NEW.run_id AND r.project_id=NEW.project_id;
                IF NOT FOUND THEN RAISE EXCEPTION 'Review run unavailable' USING ERRCODE='42501'; END IF;
                SELECT p.steps INTO workflow_steps FROM fitting_review_policies p
                    JOIN fitting_review_runs r ON r.policy_id=p.id WHERE r.id=NEW.run_id;
                SELECT count(*) INTO already FROM fitting_review_decisions WHERE run_id=NEW.run_id;
                IF NEW.stage <> already+1 OR NEW.stage > jsonb_array_length(workflow_steps)
                    OR EXISTS (SELECT 1 FROM fitting_review_decisions WHERE run_id=NEW.run_id
                        AND (decision='RETURN' OR created_by=NEW.created_by))
                    OR EXISTS (SELECT 1 FROM project_fitting_definitions WHERE revises_id=definition_row.id)
                    OR EXISTS (SELECT 1 FROM project_fitting_definitions d
                        WHERE (d.id=COALESCE(definition_row.root_id,definition_row.id)
                            OR d.root_id=COALESCE(definition_row.root_id,definition_row.id))
                        AND d.created_by=NEW.created_by)
                THEN RAISE EXCEPTION 'Review sequence or separation of duties violation' USING ERRCODE='42501'; END IF;
                SELECT pm.role_codes,pm.discipline_scope INTO signer_roles,signer_scope
                    FROM project_memberships pm JOIN users u ON u.id=pm.user_id
                    WHERE pm.project_id=NEW.project_id AND pm.user_id=NEW.created_by
                        AND u.company_id=NEW.company_id AND u.is_active;
                IF NOT FOUND OR (signer_scope IS NOT NULL AND NOT signer_scope ? 'PIPING') THEN
                    RAISE EXCEPTION 'Reviewer inactive or outside discipline' USING ERRCODE='42501'; END IF;
                selector := workflow_steps->(NEW.stage-1)->>'selector';
                IF NOT COALESCE(selector='user:'||NEW.created_by::text
                    OR (split_part(selector,':',1)='role' AND split_part(selector,':',2)=NEW.company_id::text
                        AND signer_roles ? split_part(selector,':',3)),false)
                THEN RAISE EXCEPTION 'Reviewer not assigned to this step' USING ERRCODE='42501'; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER fitting_decision_guard BEFORE INSERT ON fitting_review_decisions
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_fitting_decision();
        SQL);
    }

    private function identity(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
        $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
        $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
        $t->timestampTz('created_at');
    }

    public function down(): void
    {
        // Audit history is deliberately not silently destroyed by a rollback.
        throw new RuntimeException('Review history migration is forward-only; restore an explicitly chosen backup to revert.');
    }
};
