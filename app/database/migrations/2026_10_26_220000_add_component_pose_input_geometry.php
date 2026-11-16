<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Preserve the complete raw adapter input for immutable component pose revisions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_pose_revisions', function (Blueprint $table): void {
            $table->jsonb('input_geometry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('component_pose_revisions', function (Blueprint $table): void {
            $table->dropColumn('input_geometry');
        });
    }
};
