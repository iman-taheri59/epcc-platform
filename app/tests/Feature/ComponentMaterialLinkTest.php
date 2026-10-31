<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentMaterialService;
use App\Services\ComponentService;
use App\Services\InspectionResultService;
use App\Services\MaterialCertificateBindingService;
use App\Services\PipingCustodyAmendmentService;
use App\Services\PipingCustodyService;
use App\Services\PipingLineService;
use App\Services\PipingReceiptAccessService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ApprovedPmsClassFixture;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4b — piece-level custody: what was this component cut from?
 *
 * Everything about material that ARRIVED was already answerable, and everything
 * about joints that were WELDED was answerable too. What did not exist is the
 * sentence between them. These tests pin that sentence down, and pin down the
 * part that matters most: the heat is the custody chain's claim, never the
 * caller's.
 */
final class ComponentMaterialLinkTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();

        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'suspend' => false, 'reason' => 'Synthetic custody matrix',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => [
                'custody_mrr' => ['user:'.$this->buyer->id],
                'receiving_qc' => ['user:'.$this->qc->id],
                'acceptance_qa' => ['user:'.$this->owner->id],
                'post_grn' => ['user:'.$this->buyer->id],
            ],
        ]);

        foreach (['register_certificate', 'bind_certificate', 'link_component_material'] as $action) {
            ResultRecorderPolicyFixture::ensure(
                app(TenantContext::class), $this->project, $this->buyer, $this->buyer->company_id,
                $action, ['user:'.$this->buyer->id],
            );
        }
    }

    // ------------------------------------------------------------------ fixtures

    private function asOwner(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->project->owner_company_id, $fn, $this->project->id);
    }

    /** A line with two components and the joint between them. */
    private function pieces(): array
    {
        return $this->asOwner(function (): array {
            $lines = app(PipingLineService::class);
            $lines->configFor($this->project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $this->project->id, 'code' => 'A1'],
                ['company_id' => $this->project->owner_company_id, 'description_en' => 'CS 150#'],
            );
            ApprovedPmsClassFixture::ensure($this->project, $this->owner, $class);

            $line = $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $this->owner,
                ['nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $class->id],
            );

            $components = app(ComponentService::class);
            $tee = $components->place($line, $this->owner, 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');
            $attached = $components->attach($branch, $this->owner, 'PIPE', ['length_mm' => 1500]);

            return ['line' => $line, 'tee' => $tee, 'pipe' => $attached['component'], 'joint' => $attached['joint']];
        });
    }

    /** Custody that attests a heat; optionally amended to attest a lot too. */
    private function custody(string $heat, ?string $lot = null): string
    {
        $id = app(PipingCustodyService::class)->create($this->project, $this->buyer, [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
            'request_id' => (string) Str::uuid(),
            'source_line_id' => null,
            'unlinked_reason' => 'Source pending',
            'shipment_ref' => 'SYNTHETIC-CUSTODY', 'package_ref' => null,
            'received_at' => '2026-10-31T08:00:00+03:30',
            'observed_description' => 'Synthetic receipt', 'quantity' => '2.000', 'unit' => 'M',
            'heat_ref' => $heat, 'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q',
            'document_status' => 'MISSING', 'notes' => null,
        ]);

        if ($lot !== null) {
            app(PipingCustodyAmendmentService::class)->create($this->project, $this->buyer, $id, [
                'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
                'request_id' => (string) Str::uuid(), 'previous_amendment_id' => null,
                'source_line_id' => null, 'unlinked_reason' => 'Source pending',
                'heat_ref' => $heat, 'lot_ref' => $lot,
                'reason' => 'Record the lot for traceability',
            ]);
        }

        return $id;
    }

    private function certificate(string $heat): string
    {
        return app(InspectionResultService::class)->registerCertificate($this->project, $this->buyer, [
            'certificate_type' => 'EN10204_3_1', 'heat_ref' => $heat, 'lot_ref' => null,
            'certificate_ref' => 'MTR-'.Str::upper(Str::random(6)),
            'issuer_name' => 'Synthetic Mill', 'request_id' => (string) Str::uuid(),
        ], 'en');
    }

    private function links(): ComponentMaterialService
    {
        return app(ComponentMaterialService::class);
    }

    /** @param  array<string,mixed>  $overrides */
    private function link(string $componentId, array $overrides = []): array
    {
        return $this->links()->linkMaterial($this->project, $this->buyer, $componentId, array_replace([
            'reason' => 'Identified against the received bundle',
            'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function linkCount(): int
    {
        return app(TenantContext::class)->runAs(
            $this->buyer->company_id,
            fn () => DB::table('component_material_links')->count(),
            $this->project->id,
        );
    }

    // ------------------------------------------------------------------ the sentence

    public function test_a_piece_nobody_has_spoken_for_has_no_material_identity(): void
    {
        $p = $this->pieces();

        $this->assertNull($this->links()->effective($this->project, $this->buyer, (string) $p['pipe']->id));
        $this->assertSame(0, $this->linkCount());
    }

    public function test_a_piece_traced_to_a_receipt_carries_the_heat_the_chain_attests(): void
    {
        $p = $this->pieces();
        $custody = $this->custody('HEAT-A1');

        $link = $this->link((string) $p['pipe']->id, ['custody_id' => $custody]);

        $this->assertSame('HEAT-A1', $link['heat_ref']);
        $this->assertSame('CUSTODY_HEAT', $link['link']);
        $this->assertSame($custody, $link['custody_id']);
        $this->assertNull($link['lot_ref']);
    }

    public function test_the_heat_is_the_custody_chains_claim_not_the_callers(): void
    {
        $p = $this->pieces();
        $custody = $this->custody('HEAT-B1');

        // A caller that names a different heat is corrected to the receipt's
        // own claim, never trusted. A silent correction here would put a heat
        // on the dossier that no receipt ever attested.
        $link = $this->link((string) $p['pipe']->id, ['custody_id' => $custody, 'heat_ref' => 'HEAT-CLAIMED']);

        $this->assertSame('HEAT-B1', $link['heat_ref']);
        $this->assertSame('HEAT-B1', $this->links()->effective($this->project, $this->buyer, (string) $p['pipe']->id)['heat_ref']);
    }

    public function test_a_lot_the_chain_attests_is_carried_to_the_piece(): void
    {
        $p = $this->pieces();
        $custody = $this->custody('HEAT-C1', 'LOT-C7');

        $link = $this->link((string) $p['pipe']->id, ['custody_id' => $custody]);

        $this->assertSame('CUSTODY_LOT_HEAT', $link['link']);
        $this->assertSame('LOT-C7', $link['lot_ref']);
    }

    public function test_material_with_no_receipt_behind_it_is_an_observation_and_needs_a_reason(): void
    {
        $p = $this->pieces();

        try {
            $this->link((string) $p['pipe']->id, ['heat_ref' => 'HEAT-STAMP']);
            $this->fail('Expected a refusal of an unexplained observation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('say why', $e->errors()['material'][0]);
        }

        $this->assertSame(0, $this->linkCount());

        $link = $this->link((string) $p['pipe']->id, [
            'heat_ref' => 'HEAT-STAMP',
            'unlinked_reason' => 'Read off the piece; the mill paperwork is still travelling',
        ]);

        $this->assertSame('OBSERVED', $link['link']);
        $this->assertSame('HEAT-STAMP', $link['heat_ref']);
        $this->assertNull($link['custody_id']);
    }

    public function test_saying_what_a_piece_is_made_of_is_default_deny(): void
    {
        $p = $this->pieces();
        $custody = $this->custody('HEAT-D1');

        try {
            $this->links()->linkMaterial($this->project, $this->qc, (string) $p['pipe']->id, [
                'custody_id' => $custody,
                'reason' => 'Not nominated for this',
                'request_id' => (string) Str::uuid(),
            ], 'en');
            $this->fail('Expected a default-deny refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('nomination', $e->errors()['material'][0]);
        }

        $this->assertSame(0, $this->linkCount());
    }

    public function test_the_same_receipt_cannot_be_attributed_to_one_piece_twice(): void
    {
        $p = $this->pieces();
        $custody = $this->custody('HEAT-E1');

        $this->link((string) $p['pipe']->id, ['custody_id' => $custody]);

        try {
            $this->link((string) $p['pipe']->id, ['custody_id' => $custody]);
            $this->fail('Expected refusal of a duplicate attribution');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already traced', $e->errors()['material'][0]);
        }

        $this->assertSame(1, $this->linkCount());
    }

    public function test_a_duplicate_request_records_nothing(): void
    {
        $p = $this->pieces();
        $first = $this->custody('HEAT-F1');
        $second = $this->custody('HEAT-F2');
        $requestId = (string) Str::uuid();

        $this->link((string) $p['pipe']->id, ['custody_id' => $first, 'request_id' => $requestId]);

        try {
            $this->link((string) $p['pipe']->id, ['custody_id' => $second, 'request_id' => $requestId]);
            $this->fail('Expected refusal of a duplicate request');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already recorded', strtolower($e->errors()['material'][0]));
        }

        $this->assertSame(1, $this->linkCount());
    }

    public function test_links_are_append_only_and_the_newer_statement_is_the_truth(): void
    {
        $p = $this->pieces();
        $wrong = $this->custody('HEAT-G1');
        $right = $this->custody('HEAT-G2');

        $first = $this->link((string) $p['pipe']->id, ['custody_id' => $wrong]);
        $second = $this->link((string) $p['pipe']->id, [
            'custody_id' => $right,
            'reason' => 'The stamp says otherwise; corrected against the mill list',
        ]);

        $this->assertSame(1, $first['sequence']);
        $this->assertSame(2, $second['sequence']);

        $effective = $this->links()->effective($this->project, $this->buyer, (string) $p['pipe']->id);
        $this->assertSame('HEAT-G2', $effective['heat_ref']);
        $this->assertSame(2, $effective['sequence']);

        app(TenantContext::class)->runAs($this->buyer->company_id, function () use ($first): void {
            $before = DB::table('component_material_links')->where('id', $first['id'])->first();
            // No UPDATE/DELETE policy exists, so RLS makes the write match
            // nothing; the mutation trigger is the second line of defence.
            $this->assertSame(0, DB::table('component_material_links')->where('id', $first['id'])->update(['heat_ref' => 'HEAT-EDITED']));
            $this->assertSame(0, DB::table('component_material_links')->where('id', $first['id'])->delete());
            $this->assertEquals($before, DB::table('component_material_links')->where('id', $first['id'])->first());
        }, $this->project->id);
    }

    public function test_a_joint_reports_the_parent_metal_on_both_of_its_sides(): void
    {
        $p = $this->pieces();
        $teeHeat = $this->custody('HEAT-TEE');
        $pipeHeat = $this->custody('HEAT-PIPE');

        $this->link((string) $p['tee']->id, ['custody_id' => $teeHeat]);
        $this->link((string) $p['pipe']->id, ['custody_id' => $pipeHeat]);

        $identity = $this->links()->jointMaterial($this->project, $this->buyer, (string) $p['joint']->id);

        $this->assertNotNull($identity);
        $heats = [$identity['side_a']['heat_ref'], $identity['side_b']['heat_ref']];
        sort($heats);
        // A weld map names the parent metal on each side; that is the sentence
        // the dossier has to be able to print for every joint in a pack.
        $this->assertSame(['HEAT-PIPE', 'HEAT-TEE'], $heats);
        $this->assertNull($identity['consumable_lot_ref']);
    }

    public function test_coverage_names_the_installed_heats_with_no_certificate(): void
    {
        $p = $this->pieces();
        $certified = $this->custody('HEAT-H1');
        $bare = $this->custody('HEAT-H2');

        $this->link((string) $p['tee']->id, ['custody_id' => $certified]);
        $this->link((string) $p['pipe']->id, ['custody_id' => $bare]);

        app(MaterialCertificateBindingService::class)->bindCertificate(
            $this->project, $this->buyer, $this->certificate('HEAT-H1'),
            ['request_id' => (string) Str::uuid()], 'en',
        );

        $coverage = $this->links()->heatCoverage($this->project, $this->buyer, [
            (string) $p['tee']->id, (string) $p['pipe']->id,
        ]);

        $this->assertSame(2, $coverage['pieces']);
        $this->assertSame(2, $coverage['traced']);
        $this->assertSame([], $coverage['untraced']);
        // An installed heat nobody can certify is the finding, so it is
        // reported rather than left out of the list.
        $this->assertSame(['HEAT-H2'], $coverage['unattested']);
    }

    public function test_coverage_says_which_pieces_nobody_has_identified(): void
    {
        $p = $this->pieces();
        $this->link((string) $p['tee']->id, ['custody_id' => $this->custody('HEAT-J1')]);

        $coverage = $this->links()->heatCoverage($this->project, $this->buyer, [
            (string) $p['tee']->id, (string) $p['pipe']->id,
        ]);

        $this->assertSame(2, $coverage['pieces']);
        $this->assertSame(1, $coverage['traced']);
        $this->assertSame([(string) $p['pipe']->id], $coverage['untraced']);
    }
}
