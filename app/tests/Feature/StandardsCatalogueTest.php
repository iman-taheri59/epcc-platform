<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStandard;
use App\Models\Standard;
use App\Services\StandardsLibrary;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StandardsCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_catalogue_is_idempotent_and_project_editions_are_independent(): void
    {
        $library = app(StandardsLibrary::class);
        $standard = $library->define('ASME', 'B31.3', 'Process Piping', null, '2024', Standard::PIPING);
        $this->assertSame($standard->id, $library->define('asme', 'b 31.3', 'Another spelling')->id);
        $this->assertSame($standard->id, $library->resolve('ASME B31.3 §323')?->id);

        $project = Project::where('code', 'PH-14')->firstOrFail();
        $tenant = app(TenantContext::class);
        $adoption = $tenant->runAs($project->owner_company_id, fn () => $library->adopt($project, $standard, '2022'), $project->id);
        $this->assertSame('ASME B31.3-2022', $adoption->citation());
        $standard->update(['edition' => '2026']);
        $tenant->runAs($project->owner_company_id, function () use ($adoption, $library, $project, $standard): void {
            $this->assertTrue($adoption->fresh()->isPinnedBehindCatalogue());
            $this->assertSame('2022', $library->adopt($project, $standard)->edition);
            $this->assertCount(1, $library->forProject($project));
        }, $project->id);
    }

    public function test_withdrawal_blocks_new_adoption_but_preserves_history(): void
    {
        $library = app(StandardsLibrary::class);
        $standard = $library->define('API', '5L', 'Line Pipe');
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $tenant = app(TenantContext::class);
        $tenant->runAs($project->owner_company_id, fn () => $library->adopt($project, $standard, '2024'), $project->id);
        $library->withdraw($standard);
        $this->assertCount(0, $library->catalogue());
        $this->assertCount(1, $tenant->runAs($project->owner_company_id, fn () => $library->forProject($project), $project->id));
        $this->expectException(ValidationException::class);
        $tenant->runAs($project->owner_company_id, fn () => $library->adopt($project, $standard->fresh()), $project->id);
    }

    public function test_project_adoptions_are_hidden_without_tenant_context(): void
    {
        $library = app(StandardsLibrary::class);
        $standard = $library->define('ASME', 'B16.5', 'Flanges');
        $project = Project::where('code', 'PH-14')->firstOrFail();
        app(TenantContext::class)->runAs($project->owner_company_id, fn () => $library->adopt($project, $standard, '2025'), $project->id);
        $this->assertSame(0, ProjectStandard::count());
        $this->assertSame(0, DB::table('project_standards')->count());
    }
}
