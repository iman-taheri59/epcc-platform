<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a branch joint to be reported and numbered on either its branch line
 * or its explicit header line (ADR-006 §15, branch-line ownership decision).
 *
 * A wall root already names its header. Ordinary two-port joints farther along
 * that branch need an explicit link back to the P2P/OLET wall root before they
 * may use the header's joint register. The physical ports remain on the branch
 * line; this link changes ownership/reporting only, never topology.
 *
 * No ordinary two-port cross-line tie-in is admitted: the database guard still
 * requires both physical component sides to belong to the same branch line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joints', function (Blueprint $table): void {
            $table->uuid('branch_root_joint_id')->nullable();
            $table->foreign('branch_root_joint_id', 'joints_branch_root_joint_fk')
                ->references('id')->on('joints')->restrictOnDelete();
            $table->index('branch_root_joint_id', 'joints_branch_root_idx');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_branch_root_requires_two_ports CHECK (
                branch_root_joint_id IS NULL OR port_b_id IS NOT NULL
            )
        SQL);

        GuardSql::apply(['epcc_guard_joint_line']);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_root_requires_two_ports');

        Schema::table('joints', function (Blueprint $table): void {
            $table->dropForeign('joints_branch_root_joint_fk');
            $table->dropIndex('joints_branch_root_idx');
            $table->dropColumn('branch_root_joint_id');
        });

        // The guard reads this optional field through the NEW record's JSON
        // representation, so the current definition remains safe after the
        // column is removed on rollback.
        GuardSql::apply(['epcc_guard_joint_line']);
    }
};
