<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Joint;
use App\Models\PipingLine;
use App\Models\PipingClass;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\JointService;
use App\Services\PipingLineService;
use App\Services\PipingTestPackService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The physical things a quality record is ABOUT: a fitted joint and a ready
 * test pack.
 *
 * Building a joint is not one insert - it is a line, a class the PMS has
 * approved, a tee, a branch pipe, and then the fit-up that makes the joint
 * weldable. Every surface that records against a joint (welds and NDT here,
 * sign-offs and bindings in later slices) needs the same ground, and none of
 * them should have to know how a tee is placed.
 *
 * The pack is simpler but has the same shape of problem: a result may only be
 * recorded against a pack that is READY (or TESTED, for a retest), and a pack
 * the fixture hands back must already be in that state.
 *
 * Both are built through the real services, inside the employer's session
 * contract, because a fixture that bypasses the guards would prove nothing
 * about a screen that goes through them.
 */
final class QualityResultFixture
{
    /**
     * A joint in FITTED state - the only state a weld may be recorded against.
     *
     * $line is optional so a caller that needs the line as well (the ITR
     * dossier, which compiles a pack's lines) can build both on the same one
     * instead of creating a second line nobody asked for.
     */
    public static function fittedJoint(
        TenantContext $tenant,
        Project $project,
        User $owner,
        string $size = '6',
        ?PipingLine $line = null,
    ): Joint {
        $line ??= self::line($tenant, $project, $owner, $size);

        return $tenant->runAs($project->owner_company_id, function () use ($line, $owner): Joint {
            $components = app(ComponentService::class);
            $tee = $components->place($line, $owner, 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');
            $attached = $components->attach($branch, $owner, 'PIPE', ['length_mm' => 1500]);

            return app(JointService::class)->markFitted($attached['joint'], $owner, 'FIT-QA');
        }, $line->project_id);
    }

    /** A test pack in READY state - results may be recorded, nothing is claimed yet. */
    public static function readyPack(TenantContext $tenant, Project $project, User $owner, string $code = 'TP-QA-1'): PipingTestPack
    {
        $pack = $tenant->runAs($project->owner_company_id, fn () => app(PipingTestPackService::class)
            ->create($project, $owner, ['code' => $code]), $project->id);

        $tenant->runAs($project->owner_company_id, function () use ($pack, $owner): void {
            DB::table('piping_test_packs')->where('id', $pack->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $owner->id]);
        }, $project->id);

        // Re-read inside the session: the table is RLS+FORCE, so a refresh from
        // outside it would find the row invisible and report it as missing.
        return $tenant->runAs(
            $project->owner_company_id,
            fn (): PipingTestPack => PipingTestPack::whereKey($pack->id)->firstOrFail(),
            $project->id
        );
    }

    /** A line with an approved PMS class - the ground a joint needs. */
    public static function line(TenantContext $tenant, Project $project, User $owner, string $size = '6'): PipingLine
    {
        return $tenant->runAs($project->owner_company_id, function () use ($project, $owner, $size) {
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => $size, 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        }, $project->id);
    }
}
