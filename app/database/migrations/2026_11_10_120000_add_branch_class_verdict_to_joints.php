<?php

declare(strict_types=1);

use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\JointBranchRuleState;
use App\Support\Piping\P2pKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the PMS verdict onto the branch weld, alongside what the model saw.
 *
 * ADR-008 §9.2: branch welds must be counted and reported separately, the
 * mandatory pad thickness of a PAD rule must stay traceable to the weld, and
 * "a joint contradicting its class is a deviation". The first of those landed
 * with `branch_kind` (2026_11_10_110000). This migration supplies the other
 * two — which both need the class to be asked at the moment the joint is made.
 *
 * Two vocabularies side by side, deliberately:
 *
 *   `branch_kind`        what the MODEL OBSERVED   (JointBranchKind)
 *   `branch_class_type`  what the CLASS DECLARED   (BranchConnectionType)
 *
 * They are not the same question and neither can be derived from the other.
 * Keeping both is what makes the deviation check possible; overwriting the
 * observation with the verdict would destroy the evidence that they differ.
 *
 * The verdict is read from the line's ISSUED PMS SNAPSHOT, never from the live
 * piping-class rows. ComponentService already settled that convention for pipe
 * schedules ("inherits the line's issued PMS specification, not the
 * mutable/current piping-class rules"), and a weld is a stronger case still:
 * a joint is a physical fact, and a class revision issued next month must not
 * retroactively change what this weld was built to.
 *
 * Frozen, not referenced. There is deliberately no foreign key to
 * `piping_class_branch_rules`: the pad thickness in particular is a physical
 * datum — the pad was bought, cut and welded at that thickness — and a rule
 * that is later revised must not silently restate it.
 *
 * Nullable throughout: only branch welds carry a verdict, and a weld in the
 * run has no business answering for a branch table.
 *
 * No backfill. No project has ever been run on this software.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('joints', function (Blueprint $table): void {
            // What the class declared. Mirrors piping_class_branch_rules.
            $table->string('branch_class_type', 32)->nullable();
            $table->string('branch_class_p2p_kind', 16)->nullable();

            // Frozen from the rule, because it is physical. Millimetres.
            $table->decimal('branch_pad_thickness_mm', 8, 3)->nullable();

            $table->string('branch_rule_state', 16)->nullable();
        });

        $types = implode(', ', array_map(
            static fn (string $k): string => "'{$k}'",
            BranchConnectionType::ALL,
        ));
        $p2pKinds = implode(', ', array_map(
            static fn (string $k): string => "'{$k}'",
            P2pKind::ALL,
        ));
        $states = implode(', ', array_map(
            static fn (string $k): string => "'{$k}'",
            JointBranchRuleState::ALL,
        ));

        // NULL-explicit throughout, for the reason the class table learned the
        // hard way: a CHECK that evaluates to NULL passes, and `x IN (...)` is
        // NULL when x is NULL. Every one of these columns is nullable by design,
        // so each constraint has to say so.
        DB::unprepared(<<<SQL
            ALTER TABLE joints ADD CONSTRAINT joints_branch_class_type_valid
                CHECK (branch_class_type IS NULL OR branch_class_type IN ({$types}))
        SQL);

        DB::unprepared(<<<SQL
            ALTER TABLE joints ADD CONSTRAINT joints_branch_class_p2p_kind_valid
                CHECK (
                    (branch_class_type = 'P2P' AND branch_class_p2p_kind IS NOT NULL
                        AND branch_class_p2p_kind IN ({$p2pKinds}))
                    OR (branch_class_type IS DISTINCT FROM 'P2P' AND branch_class_p2p_kind IS NULL)
                )
        SQL);

        DB::unprepared(<<<SQL
            ALTER TABLE joints ADD CONSTRAINT joints_branch_rule_state_valid
                CHECK (branch_rule_state IS NULL OR branch_rule_state IN ({$states}))
        SQL);

        // A governed branch weld must carry its verdict; an ungoverned one must
        // say which way it is ungoverned. Without this, "state = RULE with a
        // null type" is representable, and that is the silent case again.
        DB::unprepared(<<<SQL
            ALTER TABLE joints ADD CONSTRAINT joints_branch_verdict_complete CHECK (
                branch_rule_state IS NULL
                OR (branch_rule_state = 'RULE' AND branch_class_type IS NOT NULL)
                OR (branch_rule_state <> 'RULE' AND branch_class_type IS NULL)
            )
        SQL);

        // The report that matters: every branch weld whose class said nothing.
        DB::unprepared(<<<'SQL'
            CREATE INDEX joints_ungoverned_branch_idx
                ON joints (piping_line_id)
                WHERE branch_rule_state IS NOT NULL AND branch_rule_state <> 'RULE'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS joints_ungoverned_branch_idx');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_verdict_complete');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_rule_state_valid');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_class_p2p_kind_valid');
        DB::unprepared('ALTER TABLE joints DROP CONSTRAINT IF EXISTS joints_branch_class_type_valid');

        Schema::table('joints', function (Blueprint $table): void {
            $table->dropColumn([
                'branch_class_type',
                'branch_class_p2p_kind',
                'branch_pad_thickness_mm',
                'branch_rule_state',
            ]);
        });
    }
};
