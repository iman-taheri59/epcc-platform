<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A schedule string alone is not a pipe specification: its wall depends on DN. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipe_schedule_exception_requests', function (Blueprint $t): void {
            // Nullable solely for already-recorded requests whose original
            // size cannot be reconstructed after a component was edited.
            $t->string('nps_at_request', 16)->nullable();
            $t->integer('dn_at_request')->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_exception_requests ADD CONSTRAINT pipe_schedule_request_size_pair
            CHECK ((nps_at_request IS NULL AND dn_at_request IS NULL) OR
                   (nps_at_request IS NOT NULL AND length(btrim(nps_at_request)) > 0
                    AND dn_at_request IS NOT NULL AND dn_at_request > 0))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pipe_schedule_exception_requests DROP CONSTRAINT pipe_schedule_request_size_pair');
        Schema::table('pipe_schedule_exception_requests', function (Blueprint $t): void {
            $t->dropColumn(['nps_at_request', 'dn_at_request']);
        });
    }
};
