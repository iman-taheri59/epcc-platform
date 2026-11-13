<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\PipingClassService;
use App\Services\PmsAccessService;
use App\Services\PmsClassDocumentService;
use App\Support\CdeState;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\BranchReinforcement;
use App\Support\Piping\P2pKind;
use App\Support\Piping\PipingRuleKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PmsRevisionDraftControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml targets epcc_test; never the development epcc database.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_nominated_author_can_edit_pad_material_revert_to_header_and_publish_the_reviewed_snapshot(): void
    {
        [$project, $owner, $author, $document, $class, $branchRuleId, $wipRevisionId, $oldSnapshotId] = $this->approvedPmsAndNextWip();
        $url = route('documents.pms.pad-materials', [
            'projectCode' => $project->code,
            'documentId' => $document->id,
            'lang' => 'en',
        ]);
        $postUrl = route('documents.pms.pad-materials.update', [
            'projectCode' => $project->code,
            'documentId' => $document->id,
            'classId' => $class->id,
            'branchRuleId' => $branchRuleId,
            'lang' => 'en',
        ]);

        $this->actingAs($author)->get($url)
            ->assertOk()
            ->assertViewIs('documents.pms-pad-materials')
            ->assertSee('Inherited from header PIPE_MATERIAL')
            ->assertSee('A106 B');

        // Bad material is rejected before the draft changes.
        $this->post($postUrl, [
            'mode' => [$branchRuleId => 'CUSTOM'],
            'pad_material' => [$branchRuleId => ['spec' => 'A106', 'grade' => 'UNKNOWN']],
        ])->assertSessionHasErrors('pad_material');
        $this->assertNull($this->draftSpecification($author, $project, $document->id, $wipRevisionId)['branch_rules'][0]['value']);

        // A Table A-1 selection is canonicalised and written only to the WIP copy.
        $this->post($postUrl, [
            'mode' => [$branchRuleId => 'CUSTOM'],
            'pad_material' => [$branchRuleId => ['spec' => 'a106', 'grade' => 'b']],
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $custom = $this->draftSpecification($author, $project, $document->id, $wipRevisionId);
        $this->assertSame('A106', $custom['branch_rules'][0]['value']['pad_material']['spec']);
        $this->assertSame('B', $custom['branch_rules'][0]['value']['pad_material']['grade']);

        $baseline = $this->snapshotSpecification($author, $project, $oldSnapshotId);
        $deltaAllowed = $this->tenant()->runAs($author->company_id, fn () => DB::selectOne(
            'SELECT epcc_pms_allowed_revision_delta(?::jsonb, ?::jsonb) AS allowed',
            [json_encode($baseline, JSON_THROW_ON_ERROR), json_encode($custom, JSON_THROW_ON_ERROR)],
        )->allowed, $project->id);
        $this->assertTrue((bool) $deltaAllowed, 'The database guard must accept an isolated PAD-material delta.');

        $unknownMaterialField = $custom;
        $unknownMaterialField['branch_rules'][0]['value']['pad_material']['internal_note'] = 'must not hitchhike';
        $missingManualIdentity = $custom;
        $missingManualIdentity['branch_rules'][0]['value']['pad_material']['s_mpa_override'] = 20;
        $fractionalGroup = $custom;
        $fractionalGroup['branch_rules'][0]['value']['pad_material']['s_mpa_override'] = 20;
        $fractionalGroup['branch_rules'][0]['value']['pad_material']['p_no'] = '1';
        $fractionalGroup['branch_rules'][0]['value']['pad_material']['group_no'] = 1.5;
        foreach ([$unknownMaterialField, $missingManualIdentity, $fractionalGroup] as $forged) {
            $databaseDecision = $this->tenant()->runAs($author->company_id, fn () => DB::selectOne(
                'SELECT epcc_pms_allowed_revision_delta(?::jsonb, ?::jsonb) AS allowed',
                [json_encode($baseline, JSON_THROW_ON_ERROR), json_encode($forged, JSON_THROW_ON_ERROR)],
            ), $project->id);
            $this->assertFalse((bool) $databaseDecision->allowed, 'The database guard must reject malformed or extra PAD fields.');
        }

        // A nomination suspension takes effect at the write boundary and does
        // not discard the draft; restoring the nomination allows the edit.
        app(PmsAccessService::class)->configure($project, $owner, [
            'version' => 1, 'suspend' => true, 'selectors' => [],
            'reason' => 'Verify the PAD draft respects a nomination suspension',
        ]);
        $this->post($postUrl, ['mode' => [$branchRuleId => 'HEADER']])
            ->assertSessionHasErrors('pms_access');
        $stillCustom = $this->draftSpecification($author, $project, $document->id, $wipRevisionId);
        $this->assertSame('A106', $stillCustom['branch_rules'][0]['value']['pad_material']['spec']);
        app(PmsAccessService::class)->configure($project, $owner, [
            'version' => 2, 'suspend' => false, 'selectors' => ['user:'.$author->id],
            'reason' => 'Restore the nominated PMS author for the PAD draft test',
        ]);

        // Returning to HEADER removes only the explicit override (the domain default).
        $this->post($postUrl, ['mode' => [$branchRuleId => 'HEADER']])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $inherited = $this->draftSpecification($author, $project, $document->id, $wipRevisionId);
        $this->assertNull($inherited['branch_rules'][0]['value']);

        $this->post($postUrl, [
            'mode' => [$branchRuleId => 'CUSTOM'],
            'pad_material' => [$branchRuleId => [
                'spec' => 'CUSTOM STEEL', 'grade' => 'G1', 's_mpa_override' => '138',
                'p_no' => '1', 'group_no' => '1',
            ]],
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $manual = $this->draftSpecification($author, $project, $document->id, $wipRevisionId);
        $this->assertSame(138.0, (float) $manual['branch_rules'][0]['value']['pad_material']['s_mpa_override']);
        $this->assertSame('1', $manual['branch_rules'][0]['value']['pad_material']['p_no']);
        $this->assertSame(1, $manual['branch_rules'][0]['value']['pad_material']['group_no']);

        // Carry the exact reviewed manual-S draft through CDE publication.

        $tenant = $this->tenant();
        $tenant->runAs($author->company_id, function () use ($document, $author): void {
            $register = app(DocumentRegister::class);
            $register->transition($document->refresh(), $author, CdeState::SHARED, 'S2');
            $register->transition($document->refresh(), $author, CdeState::PUBLISHED, 'A1');
        }, $project->id);

        $newSnapshot = $tenant->runAs($author->company_id, fn () => DB::table('pms_class_revision_snapshots')
            ->where('document_revision_id', $wipRevisionId)
            ->where('piping_class_id', $class->id)->first(), $project->id);
        $this->assertNotNull($newSnapshot);
        $newSpec = json_decode($newSnapshot->specification, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('CUSTOM STEEL', $newSpec['branch_rules'][0]['value']['pad_material']['spec']);
        $this->assertSame('G1', $newSpec['branch_rules'][0]['value']['pad_material']['grade']);
        $this->assertSame(138.0, (float) $newSpec['branch_rules'][0]['value']['pad_material']['s_mpa_override']);
        $this->assertSame('1', $newSpec['branch_rules'][0]['value']['pad_material']['p_no']);
        $this->assertSame(1, $newSpec['branch_rules'][0]['value']['pad_material']['group_no']);

        $oldSpec = $this->snapshotSpecification($author, $project, $oldSnapshotId);
        $this->assertNull($oldSpec['branch_rules'][0]['value'], 'The previously issued snapshot remains immutable.');
        $this->actingAs($author)->get($url)->assertOk()->assertSee('read-only');
        $this->post($postUrl, ['mode' => [$branchRuleId => 'HEADER']])
            ->assertSessionHasErrors('revision');
    }

    public function test_document_register_links_to_the_pad_screen_and_piping_scope_is_enforced(): void
    {
        [$project, , $author, $document] = $this->approvedPmsAndNextWip();

        $registerUrl = route('documents', ['projectCode' => $project->code, 'lang' => 'en']);
        $this->actingAs($author)->get($registerUrl)
            ->assertOk()
            ->assertSee(route('documents.pms.pad-materials', [
                'projectCode' => $project->code,
                'documentId' => $document->id,
                'lang' => 'en',
            ]));

        $outOfScope = $this->userByEmail('mousavi@sahand.example');
        $this->actingAs($outOfScope)->get(route('documents.pms.pad-materials', [
            'projectCode' => $project->code,
            'documentId' => $document->id,
            'lang' => 'en',
        ]))->assertNotFound();
    }

    /** @return array{Project, User, User, Document, PipingClass, string, string, string} */
    private function approvedPmsAndNextWip(): array
    {
        $tenant = $this->tenant();
        $project = $tenant->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
        $owner = $this->userByEmail('karimi@nioc.example');
        $author = $this->userByEmail('rezaei@pars.example');
        $register = app(DocumentRegister::class);
        $document = $tenant->runAs($author->company_id, fn () => $register->register($project, $author, [
            'discipline' => 'PIPING',
            'doc_type' => 'PMS',
            'title_en' => 'PAD material revision test',
            'title_fa' => 'آزمون بازنگری جنس پد',
        ]), $project->id);

        app(PmsAccessService::class)->configure($project, $owner, [
            'version' => 0,
            'suspend' => false,
            'selectors' => ['user:'.$author->id],
            'reason' => 'Nominated author for PAD-material revision test',
        ]);

        [$class, $branchRuleId] = $tenant->runAs($author->company_id, function () use ($project, $author): array {
            $classes = app(PipingClassService::class);
            $class = $classes->create($project, $author, 'PAD-REVISION-TEST', 'PAD test class', 'کلاس آزمون پد', 15, 600, 'en');
            $classes->addRule($project, $author, $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '80'], 'en');
            $classes->addRule($project, $author, $class, PipingRuleKind::CORROSION_ALLOWANCE, 15, 250, ['ca_mm' => 1.5], 'en');
            $classes->addRule($project, $author, $class, PipingRuleKind::CORROSION_ALLOWANCE, 250, 600, ['ca_mm' => 3.0], 'en');
            $classes->addRule($project, $author, $class, PipingRuleKind::PIPE_MATERIAL, 15, 600, ['spec' => 'A106', 'grade' => 'B'], 'en');
            $classes->setFluidServiceCategory($project, $author, $class, 'NORMAL', 'en');
            $classes->setDesignBasis($project, $author, $class, 3.0, 200.0, 'en');
            $branch = $classes->addBranchRule(
                $project,
                $author,
                $class,
                250,
                350,
                125,
                175,
                BranchConnectionType::P2P,
                'en',
                p2pKind: P2pKind::STUB_IN,
                reinforcement: BranchReinforcement::PAD,
                padThickness: 6.35,
            );

            return [$class, $branch->id];
        }, $project->id);

        app(PmsClassDocumentService::class)->attach($project, $author, $class->id, $document->id);
        $class = $tenant->runAs($author->company_id,
            fn () => app(PipingClassService::class)->publish($project, $author, $class, 'en'), $project->id);

        $wipRevision = $tenant->runAs($author->company_id, function () use ($register, $document, $author): object {
            $register->transition($document->refresh(), $author, CdeState::SHARED, 'S2');
            $register->transition($document->refresh(), $author, CdeState::PUBLISHED, 'A1');

            return $register->issueRevision($document->refresh(), $author, 'B');
        }, $project->id);

        $oldSnapshotId = $tenant->runAs($author->company_id, fn () => DB::table('pms_class_revision_snapshots')
            ->where('pms_document_id', $document->id)
            ->where('piping_class_id', $class->id)->value('id'), $project->id);
        $this->assertNotNull($oldSnapshotId);

        return [$project, $owner, $author, $document, $class, $branchRuleId, $wipRevision->id, $oldSnapshotId];
    }

    private function draftSpecification($actor, Project $project, string $documentId, string $revisionId): array
    {
        $raw = $this->tenant()->runAs($actor->company_id, fn () => DB::table('pms_class_revision_drafts')
            ->where('pms_document_id', $documentId)
            ->where('document_revision_id', $revisionId)->value('specification'), $project->id);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshotSpecification($actor, Project $project, string $snapshotId): array
    {
        $raw = $this->tenant()->runAs($actor->company_id, fn () => DB::table('pms_class_revision_snapshots')
            ->where('id', $snapshotId)->value('specification'), $project->id);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}
