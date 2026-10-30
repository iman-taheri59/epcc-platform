<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\MaterialCertificateBindingService;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MtrBindingFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4h slice 3 — the MTR registry and its bindings get a surface.
 *
 * The binding rule has been enforced since A.4e and could only ever be
 * exercised by SQL, and the certificate registry it binds FROM had no way in
 * either. This screen closes both ends: register the certificate you were
 * handed, then bind it to the custody that attests its heat.
 *
 * What makes a binding a fact rather than a claim is that nothing about it is
 * declared by the person binding it: the heat and lot come from the
 * certificate, the custody record from the project, and the item code from
 * custody's source line. The tests assert the derived values, not the
 * submitted ones.
 */
final class MtrBindingHttpTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private User $issuer;

    private Project $project;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->issuer = $this->userByEmail('sadeghi@pars.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        MtrBindingFixture::configureAccess($this->tenant, $this->project, $this->owner, $this->buyer, $this->qc);
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------ surface

    public function test_the_registry_is_empty_and_says_so_before_denying_the_forms(): void
    {
        $response = $this->actingAs($this->buyer)->get($this->route());

        $response->assertOk();
        $response->assertSee('id="no-certificates"', false);
        $response->assertSee('id="register-denied"', false);
        $response->assertSee(__('quality_mtr.not_nominated'), false);
    }

    public function test_each_form_appears_only_with_its_own_nomination(): void
    {
        $this->nominate($this->buyer, 'register_certificate');

        $this->actingAs($this->buyer)->get($this->route())
            ->assertSee('id="certificate-form"', false)
            ->assertDontSee('id="register-denied"', false);

        $this->nominate($this->buyer, 'bind_certificate');

        $this->actingAs($this->buyer)->get($this->route())
            ->assertDontSee('id="register-denied"', false);
    }

    public function test_the_screen_switches_to_english(): void
    {
        $this->actingAs($this->buyer)->get($this->route([], 'en'))
            ->assertOk()->assertSee('Material certificates (MTR)', false);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->route())->assertRedirect(route('login'));
    }

    public function test_someone_outside_the_project_does_not_get_the_screen(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->get($this->route())->assertForbidden();
    }

    // -------------------------------------------------------------- register

    public function test_a_nominated_actor_registers_a_certificate_through_the_screen(): void
    {
        $this->nominate($this->buyer, 'register_certificate');

        $response = $this->actingAs($this->buyer)->post($this->route([], 'fa', 'certificates'), [
            'certificate' => $this->certificateInput(),
        ]);

        $response->assertRedirect();
        $this->assertSame(1, $this->rows('material_certificates'));

        $this->actingAs($this->buyer)->get($this->route())
            ->assertSee('MTR-HTTP-1', false);
    }

    public function test_registering_a_certificate_without_a_nomination_writes_nothing(): void
    {
        $response = $this->actingAs($this->buyer)->post($this->route([], 'fa', 'certificates'), [
            'certificate' => $this->certificateInput(),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('material_certificates'), 'default-deny held');
    }

    public function test_a_certificate_without_a_heat_is_refused(): void
    {
        $this->nominate($this->buyer, 'register_certificate');

        $response = $this->actingAs($this->buyer)->post($this->route([], 'fa', 'certificates'), [
            'certificate' => $this->certificateInput(['heat_ref' => '']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('material_certificates'));
    }

    // ----------------------------------------------------------------- binding

    public function test_a_certificate_binds_to_the_custody_that_attests_its_heat(): void
    {
        $custody = $this->custody('HEAT-HTTP-1', null, linked: true);
        $certificateId = $this->certificate('HEAT-HTTP-1');
        $this->nominate($this->buyer, 'bind_certificate');

        $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId,
            'binding' => $this->bindingInput(),
        ])->assertRedirect();

        $row = $this->bindingRows()->first();
        $this->assertSame('CUSTODY_HEAT', $row->binding);
        $this->assertSame($custody, (string) $row->custody_id, 'bound to the custody that attests the heat');
        $this->assertSame('HEAT-HTTP-1', (string) $row->heat_ref, 'the heat comes from the certificate');
        $this->assertSame('MTR-PIPE', (string) $row->item_code, 'the item is derived from custody’s source line');

        $this->actingAs($this->buyer)->get($this->route(['certificate' => $certificateId]))
            ->assertSee('id="binding-history"', false)
            ->assertSee('CUSTODY_HEAT', false);
    }

    public function test_binding_a_certificate_whose_heat_nobody_received_is_refused_until_someone_says_why(): void
    {
        $certificateId = $this->certificate('HEAT-NOBODY-1');
        $this->nominate($this->buyer, 'bind_certificate');

        $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId,
            'binding' => $this->bindingInput(),
        ])->assertSessionHasErrors();

        $this->assertSame(0, $this->rows('material_certificate_bindings'));

        // With a reason it is still a fact worth keeping — as an unresolved one.
        $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId,
            'binding' => $this->bindingInput(['unlinked_reason' => 'Mill certificate arrived before the material']),
        ])->assertRedirect();

        $this->assertSame(1, $this->rows('material_certificate_bindings'));
        $this->assertSame('UNRESOLVED', $this->bindingRows()->first()->binding);

        $this->actingAs($this->buyer)->get($this->route())
            ->assertSee('id="unresolved-table"', false)
            ->assertSee('Mill certificate arrived before the material', false);
    }

    public function test_binding_without_a_nomination_writes_nothing(): void
    {
        $this->custody('HEAT-HTTP-2');
        $certificateId = $this->certificate('HEAT-HTTP-2');

        $response = $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId,
            'binding' => $this->bindingInput(),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('material_certificate_bindings'));
    }

    public function test_the_same_request_id_is_not_bound_twice(): void
    {
        $this->custody('HEAT-HTTP-3');
        $certificateId = $this->certificate('HEAT-HTTP-3');
        $this->nominate($this->buyer, 'bind_certificate');
        $payload = $this->bindingInput();

        $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId, 'binding' => $payload,
        ])->assertRedirect();

        $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId, 'binding' => $payload,
        ])->assertSessionHasErrors();

        $this->assertSame(1, $this->rows('material_certificate_bindings'), 'a resubmission did not duplicate the binding');
    }

    // ---------------------------------------------------------------- coverage

    public function test_coverage_reports_the_heat_that_has_no_certificate(): void
    {
        $this->custody('HEAT-COVERED', null, linked: true);
        $this->custody('HEAT-BARE');
        $certificateId = $this->certificate('HEAT-COVERED');
        $this->nominate($this->buyer, 'bind_certificate');

        $this->actingAs($this->buyer)->post($this->route([], 'fa', 'bindings'), [
            'certificate_id' => $certificateId,
            'binding' => $this->bindingInput(),
        ])->assertRedirect();

        // The turnover question, answered: one heat covered, one holding nothing.
        $screen = $this->actingAs($this->buyer)->get($this->route())->assertSee('id="coverage-table"', false);
        $this->assertStringContainsString('HEAT-BARE', $screen->getContent());
        $this->assertStringContainsString('HEAT-COVERED', $screen->getContent());

        $attested = $this->tenant->runAs(
            $this->buyer->company_id,
            fn () => app(MaterialCertificateBindingService::class)->coverage($this->project, $this->buyer)['attested'],
            $this->project->id
        );

        $bare = collect($attested)->firstWhere('heat_ref', 'HEAT-BARE');
        $covered = collect($attested)->firstWhere('heat_ref', 'HEAT-COVERED');
        $this->assertSame(0, (int) $bare->certificates, 'an uncovered heat is reported, never omitted');
        $this->assertSame(1, (int) $covered->certificates);
    }

    // ------------------------------------------------------------------ helpers

    private function custody(string $heat, ?string $lot = null, bool $linked = false): string
    {
        return MtrBindingFixture::custody(
            $this->tenant, $this->project, $this->buyer, $heat, $lot, $linked, $this->qc, $this->issuer,
        );
    }

    private function nominate(User $actor, string $action): void
    {
        app(ResultRecorderPolicyService::class)->configure($this->project, $actor, [
            'version' => 0,
            'target_company_id' => $actor->company_id,
            'action' => $action,
            'selectors' => ['user:'.$actor->id],
            'suspend' => false,
            'reason' => 'Fixture nomination for '.$action,
        ], 'en');
    }

    private function certificate(string $heat, ?string $lot = null): string
    {
        $this->nominate($this->buyer, 'register_certificate');

        return MtrBindingFixture::certificate($this->project, $this->buyer, $heat, $lot);
    }

    /** @param  array<string,mixed>  $overrides */
    private function certificateInput(array $overrides = []): array
    {
        return array_replace([
            'certificate_type' => 'EN10204_3_1',
            'certificate_ref' => 'MTR-HTTP-1',
            'heat_ref' => 'HEAT-HTTP-1',
            'lot_ref' => '',
            'issuer_name' => 'HTTP Fixture Mill',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    /** @param  array<string,mixed>  $overrides */
    private function bindingInput(array $overrides = []): array
    {
        return array_replace([
            'unlinked_reason' => '',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    private function bindingRows(): \Illuminate\Support\Collection
    {
        return $this->tenant->runAs(
            $this->buyer->company_id,
            fn () => DB::table('material_certificate_bindings')
                ->where('project_id', $this->project->id)->orderBy('bound_at')->orderBy('id')->get(),
            $this->project->id
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->tenant->runAs(
            $this->buyer->company_id,
            fn (): int => DB::table($table)->count(),
            $this->project->id
        );
    }

    /** @param  array<string,mixed>  $query */
    private function route(array $query = [], string $lang = 'fa', string $action = ''): string
    {
        $name = match ($action) {
            'certificates' => 'quality.mtr.certificates',
            'bindings' => 'quality.mtr.bindings',
            default => 'quality.mtr',
        };

        return route($name, ['projectCode' => $this->project->code, 'lang' => $lang] + $query);
    }
}
