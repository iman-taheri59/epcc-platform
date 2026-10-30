<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A legacy lot — a row in the warehouse ADR-047 closed behind a cutoff.
 *
 * There is exactly one way to create one now: lift the cutoff trigger, insert,
 * put the trigger back. That is not a workaround to be hidden in a test; it is
 * what a legacy row IS — evidence from before the cutoff, admitted by the same
 * guard that refuses to let anyone write one today.
 *
 * The fixture keeps the trigger handling in one place so a test never leaves
 * the cutoff disabled behind it: a test that disables a guard and forgets to
 * restore it silently weakens every assertion after it.
 */
final class LegacyLotFixture
{
    public static function legacyLot(
        TenantContext $tenant,
        Project $project,
        User $owner,
        string $itemCode = 'LEGACY-PIPE',
        ?string $heat = 'HEAT-77',
        string $lotCode = '',
        string $unit = 'M',
    ): string {
        $id = (string) Str::uuid();

        $tenant->runAs($owner->company_id, function () use ($id, $project, $owner, $itemCode, $heat, $lotCode, $unit): void {
            DB::unprepared('ALTER TABLE material_lots DISABLE TRIGGER aa_piping_legacy_stock_cutoff');
            try {
                DB::table('material_lots')->insert([
                    'id' => $id,
                    'project_id' => $project->id,
                    'company_id' => $owner->company_id,
                    'lot_code' => $lotCode !== '' ? $lotCode : 'LEG-'.$itemCode,
                    'item_code' => $itemCode,
                    'description_en' => 'Legacy fixture',
                    'description_fa' => 'قدیمی',
                    'unit' => $unit,
                    'heat_number' => $heat,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } finally {
                DB::unprepared('ALTER TABLE material_lots ENABLE TRIGGER aa_piping_legacy_stock_cutoff');
            }
        }, $project->id);

        return $id;
    }
}
