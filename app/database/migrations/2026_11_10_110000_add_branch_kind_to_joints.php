<?php

declare(strict_types=1);

use App\Support\Piping\JointBranchKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the joint register say that a joint is a branch connection.
 *
 * Owner requirement, 2026-11-10: «در پروژه‌ها معمولاً قیمت اجرا و جوشکاری
 * اتصال انشعاب از قیمت اجرا و جوشکاری جوش معمولی بیشتر است. سیستم باید بتواند
 * اتصالات انشعابی را از معمولی تفکیک کند» — branch connections cost more to
 * execute and weld than an ordinary weld, so the register has to be able to
 * separate them. ADR-008 §9.2 says the same thing in specification language:
 * branch welds must be counted and reported separately, and dia-inch must not
 * silently absorb them.
 *
 * Why a stored column and not a derivation at read time: `joint_type` is
 * already derived from the end types at creation and frozen (ADR-006 §5.3,
 * "derived attributes are frozen"). The branch kind is the same kind of fact —
 * it is decided by which ports were joined, and a port cannot change its role
 * afterwards. Deriving it on every read would also make it unindexable, and
 * the whole point is to count these things across a line.
 *
 * Why the CHECK lists STUB_IN and STUB_ON when nothing writes them: they are
 * the P-21 names and the column will take them once the header-wall reference
 * (P-22) lands. Declaring them now means a future writer cannot invent a
 * spelling the rest of the codebase does not recognise. It is NOT a claim that
 * P2P branch welds work — no code path produces them today, and the docblock on
 * JointBranchKind says so in as many words.
 *
 * Nullable, and that is the point: NULL means "a weld in the run", which is
 * the ordinary case. Only a joint sitting on a BRANCH-role port carries a kind.
 *
 * No backfill. No project has ever been run on this software, and every joint
 * that exists was created by JointService, which refuses branches across lines
 * already — so there is no history that needs an opinion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joints', function (Blueprint $table): void {
            $table->string('branch_kind', 24)->nullable();
        });

        $kinds = implode(', ', array_map(
            static fn (string $k): string => "'{$k}'",
            JointBranchKind::ALL,
        ));

        // NULL-explicit by construction: `branch_kind IN (...)` alone is NULL
        // when branch_kind is NULL, and a CHECK that evaluates to NULL passes.
        // The `IS NULL OR` is what makes "ordinary weld" a representable state
        // rather than an unconstrained one.
        DB::unprepared(<<<SQL
            ALTER TABLE joints ADD CONSTRAINT joints_branch_kind_valid
                CHECK (branch_kind IS NULL OR branch_kind IN ({$kinds}))
        SQL);

        // Branch counts are read per line, alongside the dia-inch roll-up.
        DB::unprepared(<<<'SQL'
            CREATE INDEX joints_line_branch_idx ON joints (piping_line_id, branch_kind)
                WHERE branch_kind IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS joints_line_branch_idx');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_kind_valid');

        Schema::table('joints', function (Blueprint $table): void {
            $table->dropColumn('branch_kind');
        });
    }
};
