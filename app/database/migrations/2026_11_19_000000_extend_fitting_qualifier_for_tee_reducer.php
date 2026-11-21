<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
                    template_code = 'TEE'
                    AND fitting_class_designation IS NULL
                    AND (
                        (fitting_standard IS NULL AND fitting_qualifier IS NULL)
                        OR (fitting_standard = 'B16.9' AND fitting_qualifier IS NULL)
                        OR (fitting_standard = 'B16.9' AND fitting_qualifier IN ('EQUAL', 'STRAIGHT'))
                        OR (fitting_standard = 'B16.9' AND fitting_qualifier = 'REDUCING')
                        OR (fitting_standard IS NULL AND fitting_qualifier IN ('EQUAL', 'STRAIGHT', 'REDUCING'))
                    )
                )
                OR (
                    template_code = 'REDUCER'
                    AND fitting_standard = 'B16.9'
                    AND fitting_class_designation IS NULL
                    AND fitting_qualifier IN ('CONCENTRIC', 'ECCENTRIC')
                )
                OR (
                    template_code NOT IN ('ELBOW', 'TEE', 'REDUCER')
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
};
