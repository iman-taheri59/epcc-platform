<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the joint-to-line rule true in the database, not only in PHP.
 *
 * JointService::lineFor() has always refused a joint between two lines, and
 * always will. But that refusal lives in one PHP method, and `joints` is a
 * table: anything with a database handle can write to it. The test suite
 * already does exactly that in places, and a future importer or data repair
 * script would too. A rule that only holds while everyone agrees to route
 * through one method is a convention, not a constraint.
 *
 * The guard is written to allow a nozzle on one side, because that is the
 * whole point of ADR-010 §3.1 — a nozzle is a port owner that owns no line,
 * so the joint takes its number from the pipe side. Writing this guard before
 * the nozzle path opened would have made the two fight each other.
 *
 * No backfill is needed and none is attempted: no project has ever been run on
 * this software, and any row that would violate the guard is already refused
 * by the service. The trigger is therefore created without validating history.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuardSql::apply(['epcc_guard_joint_line']);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER joint_line_guard BEFORE INSERT OR UPDATE ON joints
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_joint_line();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS joint_line_guard ON joints');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_guard_joint_line()');
    }
};
