<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthorizationDenial;
use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * The ARC §5 six-gate pipeline.
 *
 * Each test names the gate it exercises, because the ORDER of refusal is part
 * of the contract: a non-member must be refused at MEMBERSHIP before RBAC is
 * ever consulted, otherwise the response leaks whether a capability exists on
 * a project the caller cannot see.
 */
final class AuthorizationPipelineTest extends TestCase
{
    private TenantBoundPipeline $pipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->pipe = new TenantBoundPipeline(app(AuthorizationPipeline::class));
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function project(string $code): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    // ---------------- Gate 1: AUTHENTICATION ----------------

    public function test_a_disabled_account_is_refused_before_anything_else(): void
    {
        $d = $this->pipe->check($this->user('former@pars.example'), 'cert.view', $this->project('PH-14'));

        $this->assertNotNull($d);
        $this->assertSame('AUTHENTICATION', $d->gate);
        $this->assertSame('ACCOUNT_DISABLED', $d->reason);
    }

    // ---------------- Gate 2: ENTITLEMENT ----------------

    public function test_a_plan_without_the_module_is_refused(): void
    {
        // Arvand is on Starter: core + doc only, no cost module.
        $d = $this->pipe->check($this->user('hosseini@arvand.example'), 'cert.view', $this->project('KISH-1'));

        $this->assertNotNull($d);
        $this->assertSame('ENTITLEMENT', $d->gate);
        $this->assertSame('ENTITLEMENT_MISSING', $d->reason);
    }

    public function test_the_same_user_is_allowed_a_module_the_plan_does_include(): void
    {
        $this->assertTrue(
            $this->pipe->allows($this->user('hosseini@arvand.example'), 'project.view', $this->project('KISH-1'))
        );
    }

    // ---------------- Gate 3: MEMBERSHIP ----------------

    public function test_a_non_member_is_refused_at_membership_not_at_rbac(): void
    {
        // Pars's PM holds cert.view, and his plan includes cost. The ONLY
        // reason to refuse is that he is not on KISH-1 - and the gate that
        // fires must be MEMBERSHIP, so the reply cannot be used to probe
        // which capabilities exist on someone else's project.
        $d = $this->pipe->check($this->user('rezaei@pars.example'), 'cert.view', $this->project('KISH-1'));

        $this->assertNotNull($d);
        $this->assertSame('MEMBERSHIP', $d->gate);
        $this->assertSame('NOT_PROJECT_MEMBER', $d->reason);
    }

    // ---------------- Gate 4: RBAC ----------------

    public function test_a_role_without_the_capability_is_refused(): void
    {
        // The QC inspector may view certificates but must not approve them.
        $qc = $this->user('sadeghi@pars.example');
        $ph14 = $this->project('PH-14');

        $this->assertTrue($this->pipe->allows($qc, 'cert.view', $ph14));

        $d = $this->pipe->check($qc, 'cert.approve', $ph14);
        $this->assertNotNull($d);
        $this->assertSame('RBAC', $d->gate);
        $this->assertSame('CAPABILITY_NOT_GRANTED', $d->reason);
    }

    public function test_wildcard_capabilities_grant_the_whole_family(): void
    {
        // The PM's role carries "doc.*".
        $pm = $this->user('rezaei@pars.example');

        $this->assertTrue($this->pipe->allows($pm, 'doc.view', $this->project('PH-14')));
        $this->assertTrue($this->pipe->allows($pm, 'doc.publish', $this->project('PH-14')));
    }

    // ---------------- Gate 5: ABAC / scope + authority ----------------

    public function test_a_discipline_outside_the_users_scope_is_refused(): void
    {
        $eng = $this->user('mousavi@sahand.example'); // scope: CIVIL only
        $ph14 = $this->project('PH-14');

        $this->assertTrue($this->pipe->allows($eng, 'cert.view', $ph14, ['discipline' => 'CIVIL']));

        $d = $this->pipe->check($eng, 'cert.view', $ph14, ['discipline' => 'PIPING']);
        $this->assertNotNull($d);
        $this->assertSame('ABAC', $d->gate);
        $this->assertSame('SCOPE_EXCLUDED', $d->reason);
    }

    public function test_an_amount_above_the_delegated_authority_is_refused(): void
    {
        $pm = $this->user('rezaei@pars.example'); // limit 40B
        $ph14 = $this->project('PH-14');

        $this->assertTrue($this->pipe->allows($pm, 'cert.submit', $ph14, ['amount' => 1_000_000]));

        $d = $this->pipe->check($pm, 'cert.submit', $ph14, ['amount' => 43_234_500_000]);
        $this->assertNotNull($d);
        $this->assertSame('ABAC', $d->gate);
        $this->assertSame('AUTHORITY_EXCEEDED', $d->reason);
    }

    // ---------------- Gate 6: SoD ----------------

    public function test_the_same_person_cannot_submit_then_approve(): void
    {
        $rep = $this->user('karimi@nioc.example');
        $ph14 = $this->project('PH-14');

        $this->assertTrue($this->pipe->allows($rep, 'cert.approve', $ph14));

        $d = $this->pipe->check($rep, 'cert.approve', $ph14, ['actor_history' => ['cert.submit']]);
        $this->assertNotNull($d);
        $this->assertSame('SOD', $d->gate);
        $this->assertSame('SOD_CONFLICT', $d->reason);
    }

    // ---------------- Cross-cutting ----------------

    public function test_every_denial_is_audited(): void
    {
        AuthorizationDenial::query()->delete();

        $this->pipe->check($this->user('rezaei@pars.example'), 'cert.view', $this->project('KISH-1'));

        $row = AuthorizationDenial::firstOrFail();
        $this->assertSame('MEMBERSHIP', $row->gate);
        $this->assertSame('NOT_PROJECT_MEMBER', $row->reason);
        $this->assertSame('cert.view', $row->capability);
    }

    public function test_denial_messages_are_bilingual(): void
    {
        // FR-I18N-01: no untranslated string reaches a user, and "access
        // denied" is the message most likely to be screenshotted.
        $reasons = [
            ['former@pars.example', 'cert.view', 'PH-14', []],
            ['hosseini@arvand.example', 'cert.view', 'KISH-1', []],
            ['rezaei@pars.example', 'cert.view', 'KISH-1', []],
            ['sadeghi@pars.example', 'cert.approve', 'PH-14', []],
            ['mousavi@sahand.example', 'cert.view', 'PH-14', ['discipline' => 'PIPING']],
            ['rezaei@pars.example', 'cert.submit', 'PH-14', ['amount' => 99_000_000_000]],
        ];

        foreach ($reasons as [$email, $cap, $code, $ctx]) {
            $d = $this->pipe->check($this->user($email), $cap, $this->project($code), $ctx);

            $this->assertNotNull($d, "$email/$cap should be denied");
            $this->assertNotSame('', trim($d->message('en')), "$d->reason missing EN");
            $this->assertNotSame('', trim($d->message('fa')), "$d->reason missing FA");
            $this->assertNotSame($d->message('en'), $d->message('fa'), "$d->reason not translated");

            // Persian message must actually contain Persian characters.
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $d->message('fa'), "$d->reason not in Persian");
        }
    }

    public function test_a_membership_cannot_be_created_for_a_non_participating_company(): void
    {
        // Database-level guard: an invite screen bug must not be able to put a
        // user on a project their company is not party to.
        //
        // The insert runs inside the identity hatch ON PURPOSE. Since
        // project_memberships gained RLS, an unbound insert is refused by the
        // policy's WITH CHECK - which is also a QueryException, so this test
        // would still have gone green while no longer testing the
        // participation guard at all. Opening the hatch removes the RLS
        // refusal so the only thing left that can reject the row is the guard
        // this test is named after, and the assertion below pins the message.
        try {
            $this->provisionIdentity(fn () => \App\Models\ProjectMembership::create([
                'user_id' => $this->user('rezaei@pars.example')->id, // EPC-PARS
                'project_id' => $this->project('KISH-1')->id,        // Pars is not on KISH-1
                'role_codes' => ['project_manager'],
            ]));

            $this->fail('A membership was created for a non-participating company.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringNotContainsString(
                'row-level security',
                $e->getMessage(),
                'Refused by RLS rather than by the participation guard - the guard is no longer under test.'
            );
        }
    }
}


/**
 * Runs the pipeline with the actor's tenant bound.
 *
 * WHY THIS WRAPPER EXISTS
 * -----------------------
 * Gate 3 (MEMBERSHIP) reads `project_memberships`, which now carries RLS. In
 * the application the pipeline is only ever reached from inside BindTenant, so
 * a tenant is always bound. These tests call it directly, where nothing is
 * bound - and an unbound read matches no memberships, so every test would fail
 * at MEMBERSHIP no matter which gate it was written to exercise.
 *
 * Binding here reproduces the production precondition rather than weakening
 * the policy. Note it deliberately binds the ACTOR's company: that is what the
 * middleware does, and it is also what makes a cross-tenant attempt still fail
 * honestly.
 */
final class TenantBoundPipeline
{
    public function __construct(private readonly AuthorizationPipeline $inner)
    {
    }

    public function check(User $user, string $capability, ?Project $project = null, array $context = []): ?\App\Support\Denial
    {
        return app(\App\Support\TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->inner->check($user, $capability, $project, $context),
        );
    }

    public function allows(User $user, string $capability, ?Project $project = null, array $context = []): bool
    {
        return app(\App\Support\TenantContext::class)->runAs(
            $user->company_id,
            fn (): bool => $this->inner->allows($user, $capability, $project, $context),
        );
    }
}
