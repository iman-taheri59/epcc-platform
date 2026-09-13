<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * Tenant isolation for the IDENTITY tables - users and project_memberships.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * The original RLS migration covered the tables carrying project data and
 * warned, in its own comment, about "a Filament table that someone wires up
 * carelessly". It did not cover `users`. Isolation there was a
 * `->where('company_id', ...)` inside one controller, which is exactly the
 * "forgotten where clause" that RLS is the backstop for.
 *
 * Measured before the fix, bound as EPC-PARS:
 *
 *     documents visible: 5    <- narrowed correctly
 *     users visible:     9    <- every user of all eight companies
 *
 * Nothing had exposed it because no screen listed users; building the admin
 * Users resource would have published the client's representatives, the PMC's
 * inspectors and two rival EPCs' engineers, with names and email addresses.
 *
 * These tests pin the fix from both sides. Testing only that a rival's staff
 * are hidden would pass just as well on a policy that hides EVERYTHING - that
 * is the fail-closed trap this project has already hit once, where a panel
 * showing nothing at all looked like perfect isolation. So every isolation
 * assertion here is paired with a visibility assertion.
 */
final class IdentityIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function asCompany(string $code, callable $fn): mixed
    {
        $company = Company::where('code', $code)->firstOrFail();

        return app(TenantContext::class)->runAs($company->id, $fn);
    }

    /** Domains of the users visible to the given company. */
    private function visibleDomains(string $code): array
    {
        $domains = $this->asCompany($code, fn () => User::query()->get()
            ->map(fn (User $u): string => explode('@', $u->email)[1])
            ->unique()->sort()->values()->all());

        return $domains;
    }

    public function test_a_contractor_does_not_see_a_rival_contractors_staff(): void
    {
        // EPC-ARVAND is on KISH-1 only; EPC-PARS is on PH-14. They share no
        // project, so neither may enumerate the other's people.
        $parsSees = $this->visibleDomains('EPC-PARS');

        $this->assertNotContains('arvand.example', $parsSees,
            'A contractor can read a rival contractor\'s staff directory.');

        // The paired visibility assertion: hiding everything is not isolation.
        $this->assertContains('pars.example', $parsSees,
            'The contractor cannot see its own colleagues - the policy fails closed.');
    }

    public function test_counterparties_on_a_shared_project_remain_visible(): void
    {
        // A transmittal says "acknowledged by", a review says "reviewed by".
        // If the other side's user rows were hidden, those would render blank
        // on a page that is otherwise working, so the rule is participation -
        // not company.
        $parsSees = $this->visibleDomains('EPC-PARS');

        $this->assertContains('nioc.example', $parsSees, 'The employer is invisible to its own contractor.');
        $this->assertContains('pmc.example', $parsSees, 'The consultant is invisible to the contractor.');
        $this->assertContains('karan.example', $parsSees, 'The MC body is invisible to the contractor.');
    }

    public function test_a_company_on_no_shared_project_sees_almost_nobody(): void
    {
        // ARVAND is only on KISH-1, whose other participant is NIOC.
        $arvandSees = $this->visibleDomains('EPC-ARVAND');

        $this->assertContains('arvand.example', $arvandSees, 'Fails closed on its own staff.');
        $this->assertNotContains('pars.example', $arvandSees);
        $this->assertNotContains('sahand.example', $arvandSees);
        $this->assertNotContains('pmc.example', $arvandSees);
    }

    public function test_an_unbound_connection_sees_no_users_at_all(): void
    {
        // Standing rule: an unset GUC must mean "nothing", never "everything".
        // This is the difference between failing closed and failing open, and
        // it is the single assumption the whole scheme rests on.
        $this->assertSame(0, User::query()->count(),
            'With no tenant bound the user table is readable - RLS is failing OPEN.');

        $this->assertSame(0, ProjectMembership::query()->count(),
            'With no tenant bound memberships are readable - RLS is failing OPEN.');
    }

    public function test_the_identity_hatch_opens_and_closes(): void
    {
        $tenant = app(TenantContext::class);

        $this->assertFalse($tenant->identityLookupIsOpen(), 'The hatch is open before anyone asked for it.');

        $count = $tenant->forIdentityLookup(fn (): int => User::query()->count());

        $this->assertSame(9, $count, 'The authentication path cannot read the user table.');

        // NOTE: asserting "closed" HERE proves almost nothing. A top-level
        // call opens its own transaction, and SET LOCAL is discarded at COMMIT
        // whatever the code does - so this assertion passes even if the
        // `finally` that clears the GUC is deleted outright. Mutation M90
        // proved exactly that: the hatch was made to never close and all nine
        // tests in this file stayed green.
        //
        // The clearing code only does real work INSIDE an enclosing
        // transaction, which is where the application actually calls it:
        // BindTenant has already opened one. That is what the next test
        // covers, and it is the one that bites.
        $this->assertFalse($tenant->identityLookupIsOpen());
    }

    public function test_the_hatch_closes_inside_an_enclosing_transaction(): void
    {
        // The real shape: BindTenant opens a transaction, resolves the user
        // through the hatch, then runs the whole request. If the hatch does
        // not close on the way out, every query for the rest of that request -
        // every table, every page - runs with user-table isolation disabled,
        // and the commit that would have cleaned it up is minutes of work
        // away.
        $tenant = app(TenantContext::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant): void {
            $tenant->forIdentityLookup(fn () => User::query()->count());

            $this->assertFalse(
                $tenant->identityLookupIsOpen(),
                'The hatch stayed open for the remainder of the request.'
            );
        });
    }

    public function test_the_identity_hatch_closes_even_when_the_closure_throws(): void
    {
        // A bypass that leaks on the error path is a bypass that leaks: login
        // failures are the common case, not the rare one.
        $tenant = app(TenantContext::class);

        try {
            $tenant->forIdentityLookup(function (): void {
                throw new \RuntimeException('authentication blew up');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($tenant->identityLookupIsOpen(),
            'An exception left the identity hatch open for the rest of the transaction.');
    }

    public function test_a_nested_hatch_does_not_close_the_outer_one(): void
    {
        // The seeder's user factory is itself called from inside a hatch. An
        // inner call that cleared the GUC on the way out silently shut the
        // hatch the outer caller still needed - and the symptom was not an
        // error but a write that matched no rows, leaving the "disabled"
        // fixture account enabled.
        $tenant = app(TenantContext::class);

        $tenant->forIdentityLookup(function () use ($tenant): void {
            $tenant->forIdentityLookup(fn () => User::query()->count());

            $this->assertTrue($tenant->identityLookupIsOpen(),
                'A nested identity lookup closed the hatch its caller was relying on.');
        });

        $this->assertFalse($tenant->identityLookupIsOpen());
    }

    public function test_a_user_cannot_be_written_into_another_tenant(): void
    {
        // WITH CHECK, not just USING: reading is only half of isolation. A
        // contractor must not be able to plant an account inside the
        // employer's company, which would be a login into their tenant.
        $nioc = Company::where('code', 'NIOC-AFF')->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asCompany('EPC-PARS', fn () => User::create([
            'company_id' => $nioc->id,
            'name' => 'حساب کاشته‌شده',
            'email' => 'planted@nioc.example',
            'password' => bcrypt('password'),
            'is_active' => true,
            'locale' => 'fa',
        ]));
    }

    public function test_the_employer_sees_the_teams_of_its_own_projects(): void
    {
        // The employer administers PH-14, so it must be able to see every
        // participant's memberships on it - that is the team screen.
        $employerSees = $this->asCompany('NIOC-AFF', fn (): int => ProjectMembership::query()->count());
        $arvandSees = $this->asCompany('EPC-ARVAND', fn (): int => ProjectMembership::query()->count());

        $this->assertGreaterThan($arvandSees, $employerSees,
            'The employer sees no more of its own projects\' teams than an outsider does.');
    }

    public function test_a_real_session_can_open_the_panel(): void
    {
        // THE TEST THAT WAS MISSING.
        //
        // Every panel test uses actingAs(), which injects a User object
        // straight into the guard and never asks the user provider for it. So
        // when RLS made the session's user unresolvable, all 21 panel tests
        // stayed green while a real browser could not open a single /admin
        // page: Filament's Authenticate middleware resolves the user before
        // BindTenant binds a tenant, got NULL, and redirected to the login
        // screen.
        //
        // This test logs in over HTTP - through the provider, the session
        // cookie and the guard - which is the path actingAs() skips.
        $this->post('/login', [
            'email' => 'rezaei@pars.example',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticated();

        $this->get('/admin/users')->assertSuccessful();
        $this->get('/admin/documents')->assertSuccessful();
    }

    public function test_the_guard_can_resolve_a_user_by_id(): void
    {
        // THE ASSERTION THAT ACTUALLY BITES.
        //
        // The HTTP test above is weaker than it looks: phpunit.xml sets
        // SESSION_DRIVER=array, so the session does not survive between test
        // requests and Laravel keeps the already-resolved user in memory.
        // retrieveById() is therefore never called, and mutation M95 - which
        // deletes the provider override entirely, reproducing the bug that
        // redirected every real /admin request to the login screen - left the
        // whole file green.
        //
        // In a browser retrieveById() runs on EVERY request after login. So
        // this test calls the provider the way the session guard does, with
        // nothing bound, which is the precondition that made it fail.
        $expected = $this->userByEmail('rezaei@pars.example');

        $provider = \Illuminate\Support\Facades\Auth::guard('web')->getProvider();

        $resolved = $provider->retrieveById($expected->getAuthIdentifier());

        $this->assertNotNull(
            $resolved,
            'The session guard cannot resolve its own user: every authenticated request will be treated as a guest.'
        );
        $this->assertSame($expected->email, $resolved->email);
    }

    public function test_credentials_can_be_resolved_for_login(): void
    {
        // Auth::attempt() reads the row to compare the hash, with no tenant
        // bound. If this returns null nobody can log in at all.
        $provider = \Illuminate\Support\Facades\Auth::guard('web')->getProvider();

        $resolved = $provider->retrieveByCredentials(['email' => 'karimi@nioc.example']);

        $this->assertNotNull($resolved, 'Login cannot find the user by credentials.');
        $this->assertSame('karimi@nioc.example', $resolved->email);
    }
}
