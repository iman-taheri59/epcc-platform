<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Persist the explicit standard/class inputs for the separate B16.11 elbow path. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('components', function (Blueprint $table): void {
            $table->string('fitting_standard', 8)->nullable();
            $table->smallInteger('fitting_class_designation')->nullable();
        });

        // Replace the B16.9-only qualifier constraint while preserving legacy
        // rows whose B16.9 standard was implicit in 90LR/45LR. B16.11 angles
        // are exactly 45/90 and require an explicit class value in the 16-bit
        // slot; end type remains on each Port and is checked by the service.
        DB::statement('ALTER TABLE components DROP CONSTRAINT IF EXISTS components_fitting_qualifier_scope_valid');
        DB::statement(<<<'SQL'
            ALTER TABLE components
            ADD CONSTRAINT components_fitting_qualifier_scope_valid CHECK (
                (
                    template_code = 'ELBOW'
                    AND (
                        (
                            fitting_standard IS NULL
                            AND fitting_class_designation IS NULL
                            AND (
                                fitting_qualifier IS NULL
                                OR fitting_qualifier IN ('90LR', '45LR')
                            )
                        )
                        OR (
                            fitting_standard = 'B16.9'
                            AND fitting_class_designation IS NULL
                            AND fitting_qualifier IN ('90LR', '45LR')
                        )
                        OR (
                            fitting_standard = 'B16.11'
                            AND fitting_class_designation IN (2000, 3000, 6000, 9000)
                            AND fitting_qualifier IN ('45', '90')
                        )
                    )
                )
                OR (
                    template_code <> 'ELBOW'
                    AND fitting_standard IS NULL
                    AND fitting_class_designation IS NULL
                    AND fitting_qualifier IS NULL
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE components DROP CONSTRAINT IF EXISTS components_fitting_qualifier_scope_valid');
        DB::statement(<<<'SQL'
            ALTER TABLE components
            ADD CONSTRAINT components_fitting_qualifier_scope_valid CHECK (
                fitting_qualifier IS NULL
                OR (template_code = 'ELBOW' AND fitting_qualifier IN ('90LR', '45LR'))
            )
        SQL);

        Schema::table('components', function (Blueprint $table): void {
            $table->dropColumn(['fitting_standard', 'fitting_class_designation']);
        });
    }
};
