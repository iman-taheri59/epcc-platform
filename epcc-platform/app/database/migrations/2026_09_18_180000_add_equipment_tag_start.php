<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the equipment tag counter starts (the user's ruling, 2026-09-18).
 *
 * The first pump in unit 11 is `11-P-101`, not `11-P-001`: the hundreds digit
 * conventionally echoes the unit or service, so numbering from 1 produces tags
 * no process engineer recognises. But the convention is a CLIENT's, not a law -
 * some number from 001, some from 1001 - so it is configurable per project
 * with 101 as the default.
 *
 * Deliberately NOT derived from the unit code: '11' would imply 1101, 'U-2200'
 * implies nothing, and a rule that works only for numeric units would fail
 * silently on the ones that are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->integer('equipment_tag_start')->default(101);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE project_piping_config ADD CONSTRAINT project_config_equipment_tag_start_positive CHECK (
                equipment_tag_start >= 1
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->dropColumn('equipment_tag_start');
        });
    }
};
