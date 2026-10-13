<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Services\PmsAccessService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PmsAuthorPolicyTest extends TestCase
{
    public function test_nomination_is_explicit_versioned_and_revocable_without_parallel_pms_tables(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $owner = $this->userByEmail('karimi@nioc.example');
        $writer = $this->userByEmail('rezaei@pars.example');
        $policies = app(PmsAccessService::class);

        $this->assertFalse($policies->allows($project, $writer));
        $this->assertFalse($policies->allows($project, $owner));
        $policies->configure($project, $owner, [
            'version' => 0, 'suspend' => false,
            'selectors' => ['user:'.$writer->id], 'reason' => 'Synthetic author nomination',
        ]);
        $this->assertTrue($policies->allows($project, $writer));
        $this->assertFalse($policies->allows($project, $owner));
        $policies->configure($project, $owner, [
            'version' => 1, 'suspend' => true, 'reason' => 'Synthetic suspension',
        ]);
        $this->assertFalse($policies->allows($project, $writer));
        app(TenantContext::class)->runAs($owner->company_id, function () use ($project): void {
            $this->assertSame(2, DB::table('pms_author_policies')->where('project_id', $project->id)->count());
            $this->assertSame(0, DB::table('pms_author_policies')->update(['reason' => 'rewritten']));
        }, $project->id);
    }
}
