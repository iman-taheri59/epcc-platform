<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\AcceptedMtoRow;
use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\MtoStagingRow;
use App\Models\Project;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/** Slice 4: accepted immutable MTO snapshots and atomic acceptance. */
final class MtoAcceptanceTest extends TestCase
{
    private MtoStagingService $staging;

    private MtoAcceptanceService $acceptance;

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

        return $this->tenant->runAs($pars->id, fn () => app(MtoStagingService::class)->createDocument(
            $project,
            $rezaei,
            'PH-14-PIP-MTO-'.fake()->unique()->numerify('####'),
            'Piping bulk MTO',
            'MTO حجمی پایپینگ',
            'PIPING',
            MtoDocument::SOURCE_MANUAL,
            lang: 'en'
        ), $project->id);
    }

    /** @param array<string,mixed> $override */
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

    /** @param list<array<string,mixed>> $rows */
    private function draftRevision(array $rows): MtoRevision
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $document = $this->document();

        return $this->tenant->runAs($pars->id, fn () => $this->staging->createRevision(
            $document,
            $rezaei,
            'A',
            $rows,
            revisionDate: '2026-10-06',
            lang: 'en'
        ), $project->id);
    }

    public function test_accepts_all_acceptable_rows_as_one_immutable_batch(): void
    {
        $revision = $this->draftRevision([
            $this->row(),
            $this->row([
                'mto_item_no' => '20',
                'commodity_code' => 'VLV-GT-6-150',
                'raw_description' => '6" gate valve, ASME 150, carbon steel',
                'raw_quantity' => 4,
                'raw_unit' => 'EA',
            ]),
        ]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $batch = $this->tenant->runAs(
            $pars->id,
            fn () => $this->acceptance->accept($revision, 'en'),
            $project->id
        );

        $freshRevision = $this->tenant->runAs(
            $pars->id,
            fn () => MtoRevision::findOrFail($revision->id),
            $project->id
        );
        $rows = $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoRow::where('accepted_mto_batch_id', $batch->id)->orderBy('mto_item_no')->get(),
            $project->id
        );

        $this->assertSame(AcceptedMtoBatch::ACCEPTED, $batch->state);
        $this->assertSame(MtoRevision::ACCEPTED, $freshRevision->state);
        $this->assertCount(2, $rows);
        $this->assertSame('120.000', $rows[0]->gross_quantity);
        $this->assertSame('PIP-CS-6-SCH40', $rows[0]->material_commodity_code);
        $this->assertSame('VLV-GT-6-150', $rows[1]->material_commodity_code);
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
        $this->assertNotSame($rows[0]->id, $rows[0]->mto_item_no);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/i',
            $rows[0]->id
        );
        $this->assertSame(64, strlen($rows[0]->source_row_hash));
    }

    public function test_a_blocked_row_refuses_the_whole_revision_and_creates_no_snapshot(): void
    {
        $revision = $this->draftRevision([
            $this->row(),
            $this->row([
                'mto_item_no' => '20',
                'commodity_code' => 'NOT-IN-CATALOGUE',
                'raw_description' => 'Unknown imported material',
                'raw_quantity' => 4,
                'raw_unit' => 'EA',
            ]),
        ]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $this->expectException(ValidationException::class);
        try {
            $this->tenant->runAs(
                $pars->id,
                fn () => $this->acceptance->accept($revision, 'en'),
                $project->id
            );
        } finally {
            $batchCount = $this->tenant->runAs(
                $pars->id,
                fn () => AcceptedMtoBatch::where('mto_revision_id', $revision->id)->count(),
                $project->id
            );
            $rowCount = $this->tenant->runAs(
                $pars->id,
                fn () => AcceptedMtoRow::where('mto_revision_id', $revision->id)->count(),
                $project->id
            );
            $state = $this->tenant->runAs(
                $pars->id,
                fn () => MtoRevision::findOrFail($revision->id)->state,
                $project->id
            );

            $this->assertSame(0, $batchCount);
            $this->assertSame(0, $rowCount);
            $this->assertSame(MtoRevision::DRAFT, $state);
        }
    }

    public function test_duplicate_staging_rows_cannot_be_accepted_even_if_state_is_tampered(): void
    {
        $revision = $this->draftRevision([
            $this->row(),
            $this->row([
                'mto_item_no' => '10',
                'line_no' => 2,
                'raw_quantity' => 10,
            ]),
        ]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        // The staging service marks both rows blocked. Make the test stronger:
        // acceptance also recomputes duplicate identity rather than trusting a
        // mutable validation flag supplied by an import/edit path.
        $this->tenant->runAs($pars->id, function () use ($revision): void {
            MtoStagingRow::where('mto_revision_id', $revision->id)
                ->update(['validation_state' => MtoStagingRow::ACCEPTABLE, 'validation_errors' => '[]']);
        }, $project->id);

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $this->acceptance->accept($revision, 'en'),
            $project->id
        );

        $this->assertSame(0, $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoBatch::where('mto_revision_id', $revision->id)->count(),
            $project->id
        ));
    }

    public function test_accepted_rows_freeze_source_labels_when_catalogue_changes(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $batch = $this->tenant->runAs(
            $pars->id,
            fn () => $this->acceptance->accept($revision, 'en'),
            $project->id
        );
        $accepted = $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoRow::where('accepted_mto_batch_id', $batch->id)->firstOrFail(),
            $project->id
        );
        $original = [
            'description_en' => $accepted->material_description_en,
            'description_fa' => $accepted->material_description_fa,
            'commodity_code' => $accepted->material_commodity_code,
            'hash' => $accepted->source_row_hash,
        ];

        $this->tenant->runAs($pars->id, function () use ($accepted): void {
            MaterialItem::whereKey($accepted->material_item_id)->update([
                'description_en' => 'Catalogue wording changed after acceptance',
                'description_fa' => 'شرح کاتالوگ پس از پذیرش تغییر کرد',
                'commodity_code' => 'PIP-CS-6-SCH40-REVISED',
            ]);
        }, $project->id);

        $frozen = $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoRow::findOrFail($accepted->id),
            $project->id
        );

        $this->assertSame($original['description_en'], $frozen->material_description_en);
        $this->assertSame($original['description_fa'], $frozen->material_description_fa);
        $this->assertSame($original['commodity_code'], $frozen->material_commodity_code);
        $this->assertSame($original['hash'], $frozen->source_row_hash);
    }

    public function test_non_participant_cannot_see_accepted_batches_or_rows(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $batch = $this->tenant->runAs(
            $pars->id,
            fn () => $this->acceptance->accept($revision, 'en'),
            $project->id
        );

        $outsider = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'EPC-MTO-OUT-'.fake()->unique()->numerify('###'),
            'name_en' => 'Outside MTO EPC',
            'name_fa' => 'پیمانکار بیرونی MTO',
            'tenant_type' => 'EPC',
            'is_active' => true,
        ]));

        $this->assertSame(0, $this->tenant->runAs(
            $outsider->id,
            fn () => AcceptedMtoBatch::whereKey($batch->id)->count(),
            $project->id
        ));
        $this->assertSame(0, $this->tenant->runAs(
            $outsider->id,
            fn () => AcceptedMtoRow::where('mto_revision_id', $revision->id)->count(),
            $project->id
        ));
    }

    public function test_accepted_row_model_does_not_offer_mutation_path(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $batch = $this->tenant->runAs(
            $pars->id,
            fn () => $this->acceptance->accept($revision, 'en'),
            $project->id
        );
        $accepted = $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoRow::where('accepted_mto_batch_id', $batch->id)->firstOrFail(),
            $project->id
        );

        $this->expectException(LogicException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $accepted->update(['raw_description' => 'must not change']),
            $project->id
        );
    }
}
