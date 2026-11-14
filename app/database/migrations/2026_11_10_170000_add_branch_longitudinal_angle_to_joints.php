<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist explicit P2P branch-to-header beta on the wall-root joint.
 *
 * Beta is the smaller angle between the branch and header axes, in degrees.
 * It is a P2P wall-root input only; station and offset are positions and never
 * supply an angle. Existing P2P rows are left NULL rather than guessed. New
 * roots must provide a value in the supported 45..90 degree domain. The
 * current nominal PAD envelope remains separately limited to centred beta=90.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joints', function (Blueprint $table): void {
            $table->decimal('branch_longitudinal_angle_deg', 5, 2)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_branch_longitudinal_angle_valid
                CHECK (
                    branch_longitudinal_angle_deg IS NULL
                    OR (
                        joint_type IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON')
                        AND branch_longitudinal_angle_deg BETWEEN 45.00 AND 90.00
                    )
                )
        SQL);

        GuardSql::apply(['epcc_guard_joint_branch_angle']);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER joint_branch_angle_guard
                BEFORE INSERT OR UPDATE ON joints
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_joint_branch_angle()
        SQL);

        // Keep the pad completion guard tied to the recorded root beta when
        // the angle column exists. Its JSON-based lookup remains safe if this
        // additive migration is rolled back independently.
        GuardSql::apply(['epcc_guard_branch_pad_complete']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS joint_branch_angle_guard ON joints');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_guard_joint_branch_angle()');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_longitudinal_angle_valid');

        Schema::table('joints', function (Blueprint $table): void {
            $table->dropColumn('branch_longitudinal_angle_deg');
        });
    }
};
