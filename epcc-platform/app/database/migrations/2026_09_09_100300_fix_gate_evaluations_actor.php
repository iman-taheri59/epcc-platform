<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: gate_evaluations conflated "who owns the object" with "who ran the check".
 *
 * Symptom: an employer viewing a contractor's صورت‌وضعیت got a 500 —
 * `new row violates row-level security policy for table "gate_evaluations"`.
 *
 * Cause: the audit row was written with the CERTIFICATE's company_id (the
 * contractor), while the acting tenant was the employer. WITH CHECK correctly
 * refused it. RLS was right; my schema was wrong.
 *
 * The mistake was semantic, not technical. An audit row records *an actor
 * performing an action*, so its tenant column must be the ACTOR. The object's
 * owner is separate information and belongs in its own column.
 *
 *   actor_company_id   - who ran the evaluation  (the RLS tenant column)
 *   subject_company_id - who owns the object evaluated
 *
 * This also makes the audit trail more useful: "the employer checked Pars's
 * certificate at 14:32" is exactly the question an auditor asks, and the old
 * shape could not express it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP POLICY IF EXISTS gate_evaluations_tenant_isolation ON gate_evaluations');

        Schema::table('gate_evaluations', function (Blueprint $t): void {
            $t->renameColumn('company_id', 'actor_company_id');
        });

        Schema::table('gate_evaluations', function (Blueprint $t): void {
            $t->uuid('subject_company_id')->nullable();
        });

        DB::statement('UPDATE gate_evaluations SET subject_company_id = actor_company_id WHERE subject_company_id IS NULL');

        // Read: your own evaluations, or any evaluation on a project you own.
        // Write: only as yourself - you cannot forge another actor.
        DB::statement(<<<SQL
            CREATE POLICY gate_evaluations_tenant_isolation ON gate_evaluations
            USING (
                actor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                OR EXISTS (
                    SELECT 1 FROM projects p
                    WHERE p.id = gate_evaluations.project_id
                      AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                actor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS gate_evaluations_tenant_isolation ON gate_evaluations');

        Schema::table('gate_evaluations', function (Blueprint $t): void {
            $t->dropColumn('subject_company_id');
        });

        Schema::table('gate_evaluations', function (Blueprint $t): void {
            $t->renameColumn('actor_company_id', 'company_id');
        });

        DB::statement(<<<SQL
            CREATE POLICY gate_evaluations_tenant_isolation ON gate_evaluations
            USING (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                OR EXISTS (
                    SELECT 1 FROM projects p
                    WHERE p.id = gate_evaluations.project_id
                      AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            )
        SQL);
    }
};
