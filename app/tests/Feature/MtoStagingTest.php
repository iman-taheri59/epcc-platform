<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\MtoStagingRow;
use App\Models\Project;
use App\Services\MtoStagingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** MTO slice 3: document/revision/staging only; accepted snapshots are later. */
final class MtoStagingTest extends TestCase
{
    private MtoStagingService $mto;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);

        $this->mto = app(MtoStagingService::class);
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

    private function document(): MtoDocument
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->tenant->runAs($pars->id, fn () => $this->mto->createDocument(
            $project,
            $rezaei,
            'PH-14-PIP-MTO-0001',
            'Piping bulk MTO',
            'MTO حجمی پایپینگ',
            'piping',
            MtoDocument::SOURCE_MANUAL,
            lang: 'fa'
        ), $project->id);
    }

    /** @return array<string,mixed> */
    private function row(array $override = []): array
    {
        return $override + [
            'mto_item_no' => '10',
            'commodity_code' => 'PIP-CS-6-SCH40',
            'raw_description' => '6" CS pipe, SCH 40, API 5L X60 PSL2',
            'raw_quantity' => 120,
            'raw_unit' => 'M',
        ];
    }

    public function test_an_mto_document_and_draft_revision_can_stage_rows(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $revision = $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'A',
            [
                $this->row(),
                $this->row([
                    'mto_item_no' => '20',
                    'commodity_code' => 'VLV-GT-6-150',
                    'raw_description' => '6" gate valve, ASME 150, carbon steel',
                    'raw_quantity' => 4,
                    'raw_unit' => 'EA',
                ]),
            ],
            revisionDate: '2026-10-06',
            lang: 'en'
        ), $project->id);

        $rows = $revision->rows->sortBy('line_no')->values();

        $this->assertSame('DRAFT', $revision->state);
        $this->assertSame('PIPING', $document->discipline);
        $this->assertSame(2, $rows->count());
        $this->assertSame(MtoStagingRow::ACCEPTABLE, $rows[0]->validation_state);
        $this->assertSame([], $rows[0]->validation_errors);
        $this->assertNotNull($rows[0]->material_item_id);
    }

    public function test_unmatched_material_stays_in_draft_with_raw_text_preserved(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $revision = $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'B',
            [$this->row([
                'mto_item_no' => '99',
                'commodity_code' => 'UNKNOWN-CODE',
                'raw_description' => 'Unknown special item exactly as imported',
                'raw_quantity' => 2,
                'raw_unit' => 'EA',
            ])],
            lang: 'en'
        ), $project->id);

        $row = $revision->rows->first();

        $this->assertSame(MtoStagingRow::BLOCKED_UNMATCHED_MATERIAL, $row->validation_state);
        $this->assertContains('UNMATCHED_MATERIAL', $row->validation_errors);
        $this->assertSame('Unknown special item exactly as imported', $row->raw_description);
        $this->assertNull($row->material_item_id);
    }

    public function test_alias_code_does_not_resolve_as_material_identity(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $revision = $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'C',
            [$this->row(['commodity_code' => 'PIPE-6-CS-S40'])],
            lang: 'en'
        ), $project->id);

        $this->assertSame(
            MtoStagingRow::BLOCKED_UNMATCHED_MATERIAL,
            $revision->rows->first()->validation_state
        );
    }

    public function test_unit_mismatch_blocks_the_staging_row(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $revision = $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'D',
            [$this->row(['raw_unit' => 'EA'])],
            lang: 'en'
        ), $project->id);

        $row = $revision->rows->first();

        $this->assertSame(MtoStagingRow::BLOCKED_UNIT_INCOMPATIBLE, $row->validation_state);
        $this->assertContains('UNIT_INCOMPATIBLE_WITH_MATERIAL', $row->validation_errors);
    }

    public function test_duplicate_mto_item_numbers_are_blocked_inside_one_revision(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $revision = $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'E',
            [
                $this->row(['mto_item_no' => '10']),
                $this->row(['mto_item_no' => '10', 'line_no' => 2]),
            ],
            lang: 'en'
        ), $project->id);

        $states = $revision->rows->pluck('validation_state')->all();

        $this->assertSame([
            MtoStagingRow::BLOCKED_DUPLICATE_IDENTITY,
            MtoStagingRow::BLOCKED_DUPLICATE_IDENTITY,
        ], $states);
        $this->assertContains('DUPLICATE_MTO_ITEM_NO', $revision->rows->first()->validation_errors);
    }

    public function test_staging_rows_can_link_by_material_item_id_not_text(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $material = $this->tenant->runAs($pars->id, fn () => MaterialItem::where('commodity_code', 'VLV-GT-6-150')
            ->firstOrFail(), $project->id);

        $revision = $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'F',
            [$this->row([
                'mto_item_no' => '30',
                'commodity_code' => 'not-used-when-id-is-given',
                'material_item_id' => $material->id,
                'raw_description' => 'Imported valve wording from spreadsheet',
                'raw_quantity' => 4,
                'raw_unit' => 'EA',
            ])],
            lang: 'en'
        ), $project->id);

        $row = $revision->rows->first();

        $this->assertSame(MtoStagingRow::ACCEPTABLE, $row->validation_state);
        $this->assertSame($material->id, $row->material_item_id);
        $this->assertSame('Imported valve wording from spreadsheet', $row->raw_description);
    }

    public function test_non_participants_cannot_see_mto_staging(): void
    {
        $document = $this->document();
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $outsider = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'EPC-MTO-OUT',
            'name_en' => 'Outside MTO EPC',
            'name_fa' => 'پیمانکار بیرونی MTO',
            'tenant_type' => 'EPC',
            'is_active' => true,
        ]));

        $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'G',
            [$this->row()],
            lang: 'en'
        ), $project->id);

        $visibleToPars = $this->tenant->runAs($pars->id, fn () => MtoDocument::count(), $project->id);
        $visibleToOutsider = $this->tenant->runAs($outsider->id, fn () => MtoDocument::count(), $project->id);

        $this->assertSame(1, $visibleToPars);
        $this->assertSame(0, $visibleToOutsider);
    }

    public function test_empty_revision_is_refused(): void
    {
        $document = $this->document();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $this->expectException(ValidationException::class);

        $this->tenant->runAs($pars->id, fn () => $this->mto->createRevision(
            $document,
            $rezaei,
            'H',
            [],
            lang: 'en'
        ), $project->id);
    }
}
