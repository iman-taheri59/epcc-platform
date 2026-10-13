<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication + the ARC §5 authorization pipeline.
 *
 * Replaces the `?as=COMPANY` demo shortcut with real identity. The key design
 * decision: a user BELONGS TO exactly one company (its tenant). That is what
 * makes `epcc.company_id` derivable from the session rather than the URL.
 *
 * Gate order (ARC §5): AUTH -> ENTITLEMENT -> MEMBERSHIP -> RBAC -> ABAC -> SoD.
 * This migration provides the storage for gates 2, 3 and 4; gate 1 is Laravel
 * auth; gates 5 and 6 build on the same tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Users become tenant-scoped. The existing table is Laravel stock:
        // bigint id, no company. Rebuild it on the UUID envelope (ARC §6.1).
        // ---------------------------------------------------------------
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');           // the tenant this user belongs to
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('locale', 5)->default('fa');   // FA is the launch default
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestampsTz();

            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->index('company_id');
        });

        // Sessions reference users. The stock table types `user_id` as
        // bigint, which no longer matches a UUID user - login fails with
        // SQLSTATE 22P02 the moment a session is persisted.
        if (Schema::hasTable('sessions')) {
            Schema::table('sessions', function (Blueprint $t): void {
                $t->dropColumn('user_id');
            });

            Schema::table('sessions', function (Blueprint $t): void {
                $t->uuid('user_id')->nullable()->index()->after('id');
            });
        }

        // ---------------------------------------------------------------
        // Gate 2 - ENTITLEMENT. Plan is per COMPANY (SRS BR-02).
        // Capabilities are stored as a JSON array of grants; a plan grants
        // module access, not individual record permissions.
        // ---------------------------------------------------------------
        Schema::create('plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 24)->unique();          // starter | professional | full | special
            $t->string('name_en');
            $t->string('name_fa');
            $t->integer('max_projects')->nullable();   // null = unlimited
            $t->integer('max_users')->nullable();
            $t->jsonb('modules');                      // ["core","doc","awp",...]
            $t->jsonb('standards_packs');              // ["iso"] | ["iso","awp"] | ["*"]
            $t->timestampsTz();
        });

        Schema::table('companies', function (Blueprint $t): void {
            $t->uuid('plan_id')->nullable()->after('tenant_type');
            $t->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        // ---------------------------------------------------------------
        // Gate 4 - RBAC. Roles carry atomic capabilities ("cert.approve").
        // Roles are global templates; assignment is per project (gate 3).
        // ---------------------------------------------------------------
        Schema::create('roles', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 32)->unique();  // project_manager | qc_inspector | ...
            $t->string('name_en');
            $t->string('name_fa');
            $t->jsonb('capabilities');         // ["cert.view","cert.approve",...]
            $t->timestampsTz();
        });

        // ---------------------------------------------------------------
        // Gate 3 - MEMBERSHIP. A user participates in a project with roles.
        // Note the membership is (user, project): a user cannot be a member
        // of a project their COMPANY is not on - enforced below.
        // ---------------------------------------------------------------
        Schema::create('project_memberships', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('project_id');
            $t->jsonb('role_codes');                 // ["project_manager"]
            $t->jsonb('discipline_scope')->nullable(); // null = all disciplines
            $t->decimal('authority_limit', 20, 2)->nullable(); // DoA, null = plan default
            $t->timestampsTz();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->unique(['user_id', 'project_id']);
            $t->index('project_id');
        });

        // A membership is only valid if the user's company participates in the
        // project. Enforced in the database so a bug in an invite screen
        // cannot create an orphan membership that bypasses the firewall.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_membership_requires_company()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM users u
                    JOIN project_company pc
                      ON pc.company_id = u.company_id
                     AND pc.project_id = NEW.project_id
                    WHERE u.id = NEW.user_id
                ) THEN
                    RAISE EXCEPTION
                        'user % belongs to a company that does not participate in project %',
                        NEW.user_id, NEW.project_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER project_memberships_company_check
            BEFORE INSERT OR UPDATE ON project_memberships
            FOR EACH ROW EXECUTE FUNCTION epcc_membership_requires_company();
        SQL);

        // ---------------------------------------------------------------
        // Audit: every authorization denial is recorded. SRS NFR-SEC-06.
        // Not RLS-protected - it is an operator/security table, and scoping
        // it by tenant would hide exactly the cross-tenant probes we want
        // to see.
        // ---------------------------------------------------------------
        Schema::create('authorization_denials', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->uuid('company_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->string('gate', 24);        // ENTITLEMENT | MEMBERSHIP | RBAC | ABAC | SOD
            $t->string('reason', 48);      // machine-readable, ARC §5
            $t->string('capability', 64)->nullable();
            $t->string('path', 255)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['company_id', 'created_at']);
            $t->index('gate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_denials');
        DB::statement('DROP TRIGGER IF EXISTS project_memberships_company_check ON project_memberships');
        DB::statement('DROP FUNCTION IF EXISTS epcc_membership_requires_company()');
        Schema::dropIfExists('project_memberships');
        Schema::dropIfExists('roles');

        Schema::table('companies', function (Blueprint $t): void {
            $t->dropForeign(['plan_id']);
            $t->dropColumn('plan_id');
        });

        Schema::dropIfExists('plans');
        Schema::dropIfExists('users');

        // Restore the stock users table so `down` is genuinely reversible.
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
    }
};
