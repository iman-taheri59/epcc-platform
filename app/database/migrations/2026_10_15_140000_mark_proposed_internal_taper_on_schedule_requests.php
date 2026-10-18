<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Engineering intent only; Fit-up inspection is a different ITP-stage event. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipe_schedule_exception_requests', function (Blueprint $t): void {
            $t->string('proposed_remedy', 32)->default('UNDETERMINED');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_exception_requests ADD CONSTRAINT pipe_schedule_request_remedy_valid
            CHECK (proposed_remedy IN ('UNDETERMINED', 'INTERNAL_TAPER_PROPOSED'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pipe_schedule_exception_requests DROP CONSTRAINT pipe_schedule_request_remedy_valid');
        Schema::table('pipe_schedule_exception_requests', function (Blueprint $t): void {
            $t->dropColumn('proposed_remedy');
        });
    }
};
