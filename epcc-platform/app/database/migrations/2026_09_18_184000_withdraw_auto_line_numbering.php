<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withdraw automatic number allocation (the user's ruling, 2026-09-18).
 *
 * Building the equipment tag allocator exposed the flaw in the one the lines
 * already had: `max(sequence)` can only see rows that still exist, so deleting
 * a line during design hands its number back to the next one. A number that
 * has reached an isometric, a test pack or a PO must never be reused.
 *
 * The alternative - an issued-number ledger - duplicates the engineering tag
 * list, which is the authoritative register on every real project. So the
 * system validates and refuses duplicates; it does not invent.
 *
 * The flag defaults to FALSE for new projects and is set TRUE for existing
 * ones, because their lines were numbered under the old rule and turning it
 * off underneath them would break the create form mid-project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->boolean('allow_auto_line_numbers')->default(false);
        });

        // Existing projects keep the behaviour they were configured under.
        \Illuminate\Support\Facades\DB::unprepared(
            'UPDATE project_piping_config SET allow_auto_line_numbers = true'
        );

        // Equipment never had allocation in production - the mode column can go.
        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->dropColumn(['equipment_tag_mode', 'equipment_tag_start']);
        });
    }

    public function down(): void
    {
        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->dropColumn('allow_auto_line_numbers');
            $t->string('equipment_tag_mode', 16)->default('VALIDATE');
            $t->integer('equipment_tag_start')->default(101);
        });
    }
};
