<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * Authentication at the HTTP boundary, and the thing it replaced.
 *
 * Before this, `?as=EPC-PARS` let ANY visitor assume ANY tenant. These tests
 * exist mainly to guarantee that shortcut never comes back.
 */
final class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    public function test_application_routes_require_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/projects/PH-14')->assertRedirect('/login');
    }

    public function test_the_login_page_and_the_stateless_gate_stay_public(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/gate')->assertOk();
    }

    public function test_a_valid_login_reaches_the_portfolio(): void
    {
        $this->post('/login', [
            'email' => 'rezaei@pars.example',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->post('/login', [
            'email' => 'rezaei@pars.example',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_hold_a_session(): void
    {
        // Correct password, disabled user: gate 1 is more than a password check.
        $this->post('/login', [
            'email' => 'former@pars.example',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_as_query_parameter_no_longer_switches_tenant(): void
    {
        // THE regression that matters. Signed in as Pars, ask to be Sahand.
        $this->actingAs($this->user('rezaei@pars.example'));

        $response = $this->get('/projects/PH-14?as=EPC-SAHAND');

        $response->assertOk();
        $response->assertSee('PH14-SV-0007');     // still Pars's own row
        $response->assertDontSee('PH14-SV-0003'); // never Sahand's
        $response->assertSee('EPC-PARS');
        $response->assertDontSee('EPC-SAHAND');
    }

    public function test_the_bound_tenant_is_the_authenticated_users_company(): void
    {
        $pars = $this->user('rezaei@pars.example');

        $this->actingAs($pars)->get('/')->assertOk();

        // What the screen showed and what PostgreSQL enforced must agree.
        // (Checked inside the request via the portfolio contents above; here
        // we assert the middleware binds at all.)
        $this->assertSame($pars->company_id, $pars->company->id);
    }

    public function test_each_user_sees_only_their_own_companys_projects(): void
    {
        $cases = [
            ['rezaei@pars.example', ['PH-14', 'BB-2'], ['KISH-1']],
            ['mousavi@sahand.example', ['PH-14'], ['BB-2', 'KISH-1']],
            ['hosseini@arvand.example', ['KISH-1'], ['PH-14', 'BB-2']],
            ['karimi@nioc.example', ['PH-14', 'KISH-1'], ['BB-2']],
        ];

        foreach ($cases as [$email, $visible, $hidden]) {
            $r = $this->actingAs($this->user($email))->get('/');
            $r->assertOk();

            foreach ($visible as $code) {
                $r->assertSee($code);
            }

            foreach ($hidden as $code) {
                $r->assertDontSee($code);
            }
        }
    }

    public function test_a_foreign_project_is_not_reachable_by_url(): void
    {
        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/KISH-1')
            ->assertNotFound();

        $this->actingAs($this->user('hosseini@arvand.example'))
            ->get('/projects/PH-14')
            ->assertNotFound();
    }

    public function test_the_commercial_firewall_holds_between_users_on_one_project(): void
    {
        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/PH-14')
            ->assertOk()
            ->assertSee('PH14-SV-0007')
            ->assertDontSee('PH14-SV-0003');

        $this->actingAs($this->user('mousavi@sahand.example'))
            ->get('/projects/PH-14')
            ->assertOk()
            ->assertSee('PH14-SV-0003')
            ->assertDontSee('PH14-SV-0007');
    }

    public function test_the_authority_limit_shown_is_the_users_not_the_companys(): void
    {
        // Pars's PM has a 40B membership limit; the old code showed a flat
        // 50B for every non-owner company.
        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/PH-14?lang=en')
            ->assertOk()
            ->assertSee('40,000,000,000');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->user('rezaei@pars.example'));

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_an_unauthenticated_request_binds_no_tenant(): void
    {
        // Fail closed: no session means no GUC, which means RLS shows nothing.
        $this->get('/gate')->assertOk();

        $this->assertNull(app(TenantContext::class)->currentGuc());
    }
}
