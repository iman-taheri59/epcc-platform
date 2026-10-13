<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentCertificate;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * BindTenant: pushes the AUTHENTICATED user's company into `epcc.company_id`
 * for the lifetime of the request.
 *
 * This used to read `?as_company=<uuid>` - a demo shortcut that let a URL
 * choose the tenant. It now reads the session, and these tests guard the
 * property that made the change worth making: what the screen shows and what
 * PostgreSQL permits are derived from the SAME identity.
 */
final class BindTenantMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        Route::middleware(['auth', 'tenant'])->get('/_t/certs', fn () => response()->json([
            'bound' => app(TenantContext::class)->currentGuc(),
            'rows' => PaymentCertificate::orderBy('number')->pluck('number')->all(),
        ]));

        // Same route without auth, to prove the fail-closed path.
        Route::middleware('tenant')->get('/_t/open', fn () => response()->json([
            'bound' => app(TenantContext::class)->currentGuc(),
            'rows' => PaymentCertificate::orderBy('number')->pluck('number')->all(),
        ]));
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    public function test_it_binds_the_authenticated_users_company(): void
    {
        $pars = $this->user('rezaei@pars.example');

        $this->actingAs($pars)
            ->getJson('/_t/certs')
            ->assertOk()
            ->assertJson([
                'bound' => $pars->company_id,
                'rows' => ['BB2-SV-0001', 'PH14-SV-0007'],
            ]);
    }

    public function test_a_different_user_binds_a_different_tenant(): void
    {
        $sahand = $this->user('mousavi@sahand.example');

        $this->actingAs($sahand)
            ->getJson('/_t/certs')
            ->assertOk()
            ->assertJson([
                'bound' => $sahand->company_id,
                'rows' => ['PH14-SV-0003'],
            ]);
    }

    public function test_an_unauthenticated_request_binds_nothing_and_sees_nothing(): void
    {
        $this->getJson('/_t/open')
            ->assertOk()
            ->assertJson(['bound' => null, 'rows' => []]);
    }

    public function test_a_query_parameter_cannot_override_the_bound_tenant(): void
    {
        // The old shortcut, now inert.
        $pars = $this->user('rezaei@pars.example');
        $sahandId = $this->user('mousavi@sahand.example')->company_id;

        $this->actingAs($pars)
            ->getJson('/_t/certs?as_company='.$sahandId.'&as=EPC-SAHAND')
            ->assertOk()
            ->assertJson([
                'bound' => $pars->company_id,
                'rows' => ['BB2-SV-0001', 'PH14-SV-0007'],
            ]);
    }
    public function test_a_disabled_existing_session_cannot_reach_a_tenant_route(): void
    {
        // Login-time refusal is insufficient: an account can be disabled
        // after its session was created, and newer routes may not call the
        // six-gate pipeline themselves.
        $this->actingAs($this->user('former@pars.example'))
            ->getJson('/_t/certs')
            ->assertForbidden();
        $this->assertNull(app(TenantContext::class)->currentGuc());
    }

}
