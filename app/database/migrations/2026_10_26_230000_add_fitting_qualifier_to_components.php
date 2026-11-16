<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Persist the selected component fitting variant; B16.9 elbow geometry needs its qualifier. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('components', function (Blueprint $table): void {
            $table->string('fitting_qualifier', 16)->nullable();
        });

        // The first geometry slice only resolves B16.9 BW long-radius elbows.
        // NULL remains valid for unrelated components and legacy/unresolved
        // instances; unsupported variants cannot be mislabeled as supported.
        DB::statement(<<<'SQL'
            ALTER TABLE components
            ADD CONSTRAINT components_fitting_qualifier_scope_valid CHECK (
                fitting_qualifier IS NULL
                OR (template_code = 'ELBOW' AND fitting_qualifier IN ('90LR', '45LR'))
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE components DROP CONSTRAINT IF EXISTS components_fitting_qualifier_scope_valid');
        Schema::table('components', function (Blueprint $table): void {
            $table->dropColumn('fitting_qualifier');
        });
    }
};
