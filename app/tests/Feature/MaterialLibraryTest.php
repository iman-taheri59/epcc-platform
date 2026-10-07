<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\Project;
use App\Services\MaterialCatalogueService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** MTO foundation: stable material identity before accepted MTO snapshots. */
final class MaterialLibraryTest extends TestCase
{
    private MaterialCatalogueService $catalogue;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->catalogue = app(MaterialCatalogueService::class);
        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    /** @return array<string,mixed> */
    private function pipeData(array $override = []): array
    {
        return $override + [
            'discipline' => 'piping',
            'category' => MaterialItem::CATEGORY_PIPE,
            'commodity_code' => ' pip-cs-8-sch40 ',
            'description_en' => '8" carbon steel pipe, SCH 40, API 5L X60 PSL2',
            'description_fa' => 'لوله فولادی کربنی ۸ اینچ، رده ۴۰، API 5L X60 PSL2',
            'base_unit' => 'm',
            'size_nps' => '8"',
            'size_dn' => 'DN200',
            'schedule' => 'SCH40',
            'class_code' => 'CS-150',
            'material_spec' => 'API 5L PSL2',
            'grade' => 'X60',
            'alias_codes' => ['PIPE-8-CS-S40', ' pipe-8-cs-s40 '],
        ];
    }

    public function test_a_project_overlay_material_item_can_be_created(): void
    {
        $pars = $this->company('EPC-PARS');
        $project = $this->project();

        $item = $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->upsertProjectItem($project, $this->pipeData(), 'fa'),
            $project->id
        );

        $this->assertSame($project->id, $item->project_id);
        $this->assertSame('PIPING', $item->discipline);
        $this->assertSame('PIP-CS-8-SCH40', $item->commodity_code);
        $this->assertSame('M', $item->base_unit);
        $this->assertSame(['PIPE-8-CS-S40'], $item->alias_codes);
        $this->assertTrue($item->isProjectOverlay());
    }

    public function test_upsert_updates_the_project_code_instead_of_duplicating_it(): void
    {
        $pars = $this->company('EPC-PARS');
        $project = $this->project();

        $first = $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->upsertProjectItem($project, $this->pipeData(), 'en'),
            $project->id
        );

        $second = $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->upsertProjectItem($project, $this->pipeData([
                'description_en' => '8" CS pipe, updated project catalogue wording',
            ]), 'en'),
            $project->id
        );

        $count = $this->tenant->runAs($pars->id, fn () => MaterialItem::where('project_id', $project->id)
            ->where('commodity_code', 'PIP-CS-8-SCH40')->count(), $project->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $count);
        $this->assertSame('8" CS pipe, updated project catalogue wording', $second->description_en);
    }

    public function test_descriptions_are_bilingual_and_units_are_controlled(): void
    {
        $pars = $this->company('EPC-PARS');
        $project = $this->project();

        $this->expectException(ValidationException::class);

        $this->tenant->runAs($pars->id, fn () => $this->catalogue->upsertProjectItem($project, $this->pipeData([
            'description_fa' => '',
            'base_unit' => 'box',
        ]), 'en'), $project->id);
    }

    public function test_material_catalogue_is_isolated_by_project_participation(): void
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $outsider = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'EPC-OUT',
            'name_en' => 'Outsider EPC',
            'name_fa' => 'پیمانکار بیرونی',
            'tenant_type' => 'EPC',
            'is_active' => true,
        ]));

        $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->upsertProjectItem($project, $this->pipeData(), 'fa'),
            $project->id
        );

        $visibleToPars = $this->tenant->runAs(
            $pars->id,
            fn () => MaterialItem::where('commodity_code', 'PIP-CS-8-SCH40')->count(),
            $project->id
        );

        $visibleToOutsider = $this->tenant->runAs(
            $outsider->id,
            fn () => MaterialItem::where('commodity_code', 'PIP-CS-8-SCH40')->count(),
            $project->id
        );

        $this->assertSame(1, $visibleToPars);
        $this->assertSame(0, $visibleToOutsider);
    }

    public function test_alias_codes_are_not_treated_as_identity(): void
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->upsertProjectItem($project, $this->pipeData(), 'fa'),
            $project->id
        );

        $exact = $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->findByCommodityCode($project, 'piping', 'pip-cs-8-sch40'),
            $project->id
        );

        $alias = $this->tenant->runAs(
            $pars->id,
            fn () => $this->catalogue->findByCommodityCode($project, 'piping', 'PIPE-8-CS-S40'),
            $project->id
        );

        $this->assertNotNull($exact);
        $this->assertNull($alias);
    }

    public function test_seed_path_creates_synthetic_piping_catalogue(): void
    {
        $this->seed(MaterialCatalogueSeeder::class);

        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $codes = $this->tenant->runAs($pars->id, fn () => MaterialItem::query()
            ->where('project_id', $project->id)
            ->orderBy('commodity_code')
            ->pluck('commodity_code')
            ->all(), $project->id);

        $this->assertSame([
            'GSK-SPW-6-150-SS-GR',
            'PIP-CS-6-SCH40',
            'VLV-GT-6-150',
        ], $codes);
    }
}
