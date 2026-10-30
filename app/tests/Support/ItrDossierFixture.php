<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\PipingLine;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\PipingTestPackService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * A test pack with something in it — the thing an ITR compiles.
 *
 * A dossier is assembled from a pack's LINES, and a line only belongs to a pack
 * once it is assigned; a pack with no lines compiles an empty dossier that
 * reports itself complete, which is the most dangerous kind of green. So the
 * fixture builds the line, fits a joint on it, creates the pack, assigns the
 * line, and only then puts the pack in the state you asked for.
 *
 * The state is forced with a direct UPDATE rather than `transition()`, because
 * a transition demands the evidence this fixture is about to fake (a recorded
 * result, a passed result) — and an ITR test needs to control completeness
 * precisely, including the case where it is NOT complete.
 */
final class ItrDossierFixture
{
    public static function pack(
        TenantContext $tenant,
        Project $project,
        User $owner,
        string $state = 'READY',
        string $code = 'TP-ITR-1',
    ): PipingTestPack {
        $line = QualityResultFixture::line($tenant, $project, $owner);
        QualityResultFixture::fittedJoint($tenant, $project, $owner, '6', $line);

        $pack = $tenant->runAs(
            $project->owner_company_id,
            fn (): PipingTestPack => app(PipingTestPackService::class)->create($project, $owner, [
                'code' => $code,
                'title_en' => 'ITR fixture pack',
                'title_fa' => 'بستهٔ آزمایشی',
            ]),
            $project->id
        );

        $tenant->runAs(
            $project->owner_company_id,
            fn (): PipingLine => app(PipingTestPackService::class)->assignLine($line, $owner, $pack),
            $project->id
        );

        if ($state !== 'DRAFT') {
            $tenant->runAs($project->owner_company_id, function () use ($pack, $owner, $state): void {
                DB::table('piping_test_packs')->where('id', $pack->id)->update([
                    'state' => $state,
                    'ready_at' => now(),
                    'ready_by' => $owner->id,
                ]);
            }, $project->id);
        }

        return $tenant->runAs(
            $project->owner_company_id,
            fn (): PipingTestPack => PipingTestPack::whereKey($pack->id)->firstOrFail(),
            $project->id
        );
    }
}
