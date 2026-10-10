<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoRow;
use App\Models\Company;
use App\Models\EngineeringEstimateRow;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\Project;
use App\Services\EngineeringEstimateService;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Slice 5: provenance-bearing net need and gross/net comparison. */
final class EngineeringEstimateTest extends TestCase
{
    private MtoStagingService $staging;

    private MtoAcceptanceService $acceptance;

    private EngineeringEstimateService $estimates;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);

        $this->staging = app(MtoStagingService::class);
        $this->acceptance = app(MtoAcceptanceService::class);
        $this->estimates = app(EngineeringEstimateService::class);
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

    private function acceptedPipe(): AcceptedMtoRow
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $user = $this->userByEmail('rezaei@pars.example');

        $document = $this->tenant->runAs($pars->id, fn () => $this->staging->createDocument(
            $project,
            $user,
            'PH-14-PIP-MTO-'.fake()->unique()->numerify('####'),
            'Piping bulk MTO',
            'MTO حجمی پایپینگ',
            'PIPING',
            MtoDocument::SOURCE_MANUAL,
            lang: 'en'
        ), $project->id);

        $revision = $this->tenant->runAs($pars->id, fn () => $this->staging->createRevision(
            $document,
            $user,
            'A',
            [[
                'mto_item_no' => '10',
                'commodity_code' => 'PIP-CS-6-SCH40',
                'raw_description' => '6 inch carbon steel pipe',
                'raw_quantity' => 120,
                'raw_unit' => 'M',
            ]],
            revisionDate: '2026-10-06',
            lang: 'en'
        ), $project->id);

        $batch = $this->tenant->runAs(
            $pars->id,
            fn () => $this->acceptance->accept($revision, 'en'),
            $project->id
        );

        return $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoRow::where('accepted_mto_batch_id', $batch->id)->firstOrFail(),
            $project->id
        );
    }

    /** @return array<string,mixed> */
    private function estimateAttributes(AcceptedMtoRow $accepted, float $net): array
    {
        return [
            'source_type' => EngineeringEstimateRow::SOURCE_MANUAL_ISO,
            'source_revision' => 'ISO-2201-A',
            'source_evidence' => 'ISO-2201 sheet 4, line take-off',
            'material_item_id' => $accepted->material_item_id,
            'accepted_mto_row_id' => $accepted->id,
            'net_quantity' => $net,
            'unit' => 'M',
            'scope_id' => $accepted->scope_id,
            'provenance' => ['document' => 'ISO-2201', 'sheet' => '4', 'line' => '10'],
        ];
    }

    public function test_a_positive_shortage_is_reported_without_percentage_tolerance(): void
    {
        $accepted = $this->acceptedPipe();
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $user = $this->userByEmail('rezaei@pars.example');

        $estimate = $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->create($project, $user, $this->estimateAttributes($accepted, 120.001), 'en'),
            $project->id
        );
        $comparison = $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->compare($estimate, 'en'),
            $project->id
        );

        $this->assertSame('SHORTAGE', $comparison['status']);
        $this->assertSame('120.000', $comparison['gross_quantity']);
        $this->assertSame('120.001', $comparison['net_quantity']);
        $this->assertSame('0.001', $comparison['shortage_quantity']);
        $this->assertSame('0.001', $comparison['delta_quantity']);
    }

    public function test_gross_above_net_is_delta_only_not_a_negative_shortage(): void
    {
        $accepted = $this->acceptedPipe();
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $user = $this->userByEmail('rezaei@pars.example');

        $estimate = $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->create($project, $user, $this->estimateAttributes($accepted, 100), 'en'),
            $project->id
        );
        $comparison = $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->compare($estimate, 'en'),
            $project->id
        );

        $this->assertSame('DELTA_ONLY', $comparison['status']);
        $this->assertSame('20.000', $comparison['delta_quantity']);
        $this->assertSame('0.000', $comparison['shortage_quantity']);
        $this->assertSame('20.000', $comparison['over_plan_quantity']);
    }

    public function test_unlinked_estimate_is_provisional_and_has_no_shortage_claim(): void
    {
        $accepted = $this->acceptedPipe();
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $user = $this->userByEmail('rezaei@pars.example');
        $attributes = $this->estimateAttributes($accepted, 140);
        unset($attributes['accepted_mto_row_id']);

        $estimate = $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->create($project, $user, $attributes, 'en'),
            $project->id
        );
        $comparison = $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->compare($estimate, 'en'),
            $project->id
        );

        $this->assertTrue($estimate->isProvisional());
        $this->assertSame('PROVISIONAL', $comparison['status']);
        $this->assertNull($comparison['gross_quantity']);
        $this->assertNull($comparison['shortage_quantity']);
    }

    public function test_description_or_wrong_material_cannot_link_an_estimate(): void
    {
        $accepted = $this->acceptedPipe();
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $user = $this->userByEmail('rezaei@pars.example');
        $attributes = $this->estimateAttributes($accepted, 120);
        $attributes['material_item_id'] = $this->tenant->runAs(
            $pars->id,
            fn () => \App\Models\MaterialItem::where('commodity_code', 'VLV-GT-6-150')->firstOrFail()->id,
            $project->id
        );
        $attributes['unit'] = 'EA';

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->create($project, $user, $attributes, 'en'),
            $project->id
        );
    }

    public function test_non_participant_cannot_see_estimate_rows(): void
    {
        $accepted = $this->acceptedPipe();
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $user = $this->userByEmail('rezaei@pars.example');

        $this->tenant->runAs(
            $pars->id,
            fn () => $this->estimates->create($project, $user, $this->estimateAttributes($accepted, 120), 'en'),
            $project->id
        );

        $outsider = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'EPC-EST-OUT-'.fake()->unique()->numerify('###'),
            'name_en' => 'Outside Estimate EPC',
            'name_fa' => 'پیمانکار بیرونی برآورد',
            'tenant_type' => 'EPC',
            'is_active' => true,
        ]));

        $visible = $this->tenant->runAs(
            $outsider->id,
            fn () => EngineeringEstimateRow::where('project_id', $project->id)->count(),
            $project->id
        );

        $this->assertSame(0, $visible);
    }
}
