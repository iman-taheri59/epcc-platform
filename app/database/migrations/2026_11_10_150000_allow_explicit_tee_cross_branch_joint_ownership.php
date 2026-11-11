<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a TEE/CROSS BRANCH-port weld be numbered on an explicitly selected
 * same-project header line, without inventing a P2P/OLET wall root.
 *
 * The branch fitting and both physical ports remain on one physical line. The
 * selected header line is a separate, auditable register owner; it does not
 * authorize an ordinary cross-line port-to-port tie-in. Wall-root-linked
 * ownership continues to use branch_root_joint_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joints', function (Blueprint $table): void {
            $table->uuid('branch_header_line_id')->nullable();
            $table->foreign('branch_header_line_id', 'joints_branch_header_line_fk')
                ->references('id')->on('piping_lines')->restrictOnDelete();
            $table->index('branch_header_line_id', 'joints_branch_header_line_idx');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE joints ADD CONSTRAINT joints_branch_header_line_owner CHECK (
                branch_header_line_id IS NULL OR (
                    port_b_id IS NOT NULL
                    AND branch_root_joint_id IS NULL
                    AND branch_header_line_id = piping_line_id
                )
            )
        SQL);

        GuardSql::apply(['epcc_guard_joint_line']);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Explicit TEE/CROSS header-line ownership is data-bearing; reassign those joints through a dedicated migration before removing this schema.'
        );
    }
};
