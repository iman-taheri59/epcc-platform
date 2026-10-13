<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flange rating and facing on a port (ADR-010 section 3.4).
 *
 * Phase 3b exposed a gap: the compatibility engine could compare bore, wall
 * and end preparation, but NOT pressure class. A 6" 300# RF nozzle accepts a
 * 6" 150# RF flange on every check the system had - same bore, same end type,
 * same schedule - and the mismatch is only discovered at bolt-up, on site,
 * with the crane hired. ADR-010 calls this out as the expensive one.
 *
 * Nullable, because bore-and-end is still enough to judge a butt weld: pipe
 * has no rating, and demanding one would make every weld a warning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ports', function (Blueprint $t): void {
            $t->string('rating', 16)->nullable()->after('end_type');
            $t->string('facing', 16)->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('ports', function (Blueprint $t): void {
            $t->dropColumn(['rating', 'facing']);
        });
    }
};
