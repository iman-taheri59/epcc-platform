<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Standalone trigger functions survive migrate:fresh's table-only drop. */
final class PipeScheduleMigrationRebuildTest extends TestCase
{
    public function test_fresh_can_rebuild_schedule_guards_twice_without_weakening_them(): void
    {
        foreach ([1, 2] as $rebuild) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            foreach ([
                'pipe_schedule_exception_requests' => 'epcc_pipe_schedule_exception_guard',
                'pipe_schedule_exception_decisions' => 'epcc_pipe_schedule_exception_guard',
                'pipe_schedule_external_signatures' => 'epcc_pipe_offline_signature_guard',
                'pipe_schedule_signature_verifications' => 'epcc_pipe_offline_signature_guard',
                'pipe_schedule_discrepancy_reports' => 'epcc_pipe_discrepancy_report_guard',
            ] as $table => $function) {
                $guard = DB::selectOne(<<<'SQL'
                    SELECT c.relrowsecurity, c.relforcerowsecurity,
                           pg_get_functiondef(p.oid) AS definition, t.tgtype::int AS trigger_type
                    FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    JOIN pg_trigger t ON t.tgrelid = c.oid AND NOT t.tgisinternal
                    JOIN pg_proc p ON p.oid = t.tgfoid
                    WHERE n.nspname = current_schema() AND c.relname = ? AND p.proname = ?
                    SQL, [$table, $function]);
                $this->assertNotNull($guard, "Rebuild {$rebuild}: missing guard for {$table}");
                $this->assertTrue($guard->relrowsecurity);
                $this->assertTrue($guard->relforcerowsecurity);
                $this->assertSame(31, (int) $guard->trigger_type);
                $this->assertStringContainsString("IF TG_OP <> 'INSERT'", $guard->definition);
                $this->assertStringContainsString("ERRCODE = '23514'", $guard->definition);
            }
        }
    }
}
