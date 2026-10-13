<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core EPCC tables.
 *
 * Two structural decisions are load-bearing here:
 *
 * 1. ADR-002 Seam 1 — ONE `work_packages` table with a `type` discriminator
 *    and a self-referencing `parent_id`. Enabling AWP later (IWP/CWP/EWP/PWP)
 *    is then an enum value plus a pack switch, NOT a schema migration on
 *    air-gapped customer installs.
 *
 * 2. ARC §5.1 — tenant isolation is enforced by PostgreSQL RLS as a backstop
 *    beneath the application's authorization, so an ORM mistake cannot leak
 *    another company's data. See the following migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Companies (tenants): OWN, EPC, PMC, SUB, VEN, MC, TPI, CB, INT ----
        Schema::create('companies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 32)->unique();
            $t->string('name_en');
            $t->string('name_fa');
            $t->string('tenant_type', 8); // SRS: 9 tenant types
            $t->timestampsTz();
        });

        Schema::create('projects', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 32)->unique();
            $t->string('name_en');
            $t->string('name_fa');
            // Which standards pack governs this project (user requirement:
            // the standards framework must be SELECTABLE, not hardcoded).
            $t->string('standards_pack', 64)->default('ips-iran');
            $t->uuid('owner_company_id');
            $t->timestampsTz();

            $t->foreign('owner_company_id')->references('id')->on('companies');
        });

        // Which companies participate in which project, and in what role.
        Schema::create('project_company', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('role', 16); // CONTRACTOR, CONSULTANT, MC, TPI, EMPLOYER
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->unique(['project_id', 'company_id']);
        });

        // ---- ADR-002 Seam 1: one table, discriminated ----
        Schema::create('work_packages', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id'); // owning company - the RLS tenant column
            $t->string('code', 64);
            $t->string('title_en');
            $t->string('title_fa');
            // FIELD_PACKAGE now; IWP/CWP/EWP/PWP/TWP become valid values when
            // an AWP pack is enabled. No migration required (ADR-002).
            $t->string('type', 16)->default('FIELD_PACKAGE');
            $t->uuid('parent_id')->nullable();
            $t->string('state', 32)->default('DRAFT');
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies');
            $t->unique(['project_id', 'code']);
            $t->index(['project_id', 'type']);
        });

        // The self-referencing FK must be added after the table (and therefore
        // its primary key) exists.
        Schema::table('work_packages', function (Blueprint $t): void {
            $t->foreign('parent_id')->references('id')->on('work_packages')->nullOnDelete();
        });

        // ---- صورت‌وضعیت / interim payment certificate ----
        Schema::create('payment_certificates', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id'); // the contractor claiming - RLS tenant column
            $t->string('number', 64);
            $t->unsignedInteger('serial'); // شماره صورت‌وضعیت
            $t->string('state', 32)->default('DRAFT');

            // Money. numeric(20,0) because IRR amounts are large and must never
            // be floats. Values are stored in ریال.
            $t->decimal('gross_amount', 20, 0)->default(0);
            $t->decimal('price_adjustment', 20, 0)->nullable();   // تعدیل
            $t->decimal('retention', 20, 0)->nullable();          // حسن انجام کار
            $t->decimal('advance_recovery', 20, 0)->nullable();   // بازپرداخت پیش‌پرداخت
            $t->decimal('tax', 20, 0)->nullable();
            $t->decimal('net_amount', 20, 0)->default(0);

            // Facts the release gate reads.
            $t->boolean('consultant_verified')->default(false);
            $t->jsonb('measurement_sheets')->default(DB::raw("'[]'::jsonb"));

            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies');
            $t->unique(['project_id', 'number']);
        });

        // ---- Append-only audit of every gate evaluation ----
        // SRS requires traceability; a release decision must be reconstructable.
        Schema::create('gate_evaluations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('object_type', 64);
            $t->uuid('object_id');
            $t->string('pack_id', 64);
            $t->string('pack_version', 32);
            $t->boolean('passed');
            $t->boolean('blocked');
            $t->jsonb('failures');
            $t->jsonb('facts');
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['object_type', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_evaluations');
        Schema::dropIfExists('payment_certificates');
        Schema::dropIfExists('work_packages');
        Schema::dropIfExists('project_company');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('companies');
    }
};
