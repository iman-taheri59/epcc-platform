<?php

namespace Tests;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Look up a user by email for `actingAs(...)`.
     *
     * WHY THIS HELPER EXISTS
     * ----------------------
     * `users` carries RLS (2026_09_12_100000_secure_identity_tables), and a
     * test method runs with no tenant bound - there is no middleware around
     * it. So the ordinary `User::where('email', ...)->firstOrFail()` that
     * nearly every test used to start with now correctly matches nothing and
     * throws ModelNotFoundException.
     *
     * That is the policy working, not a fault to be patched around by
     * weakening it. Fetching the identity you are about to authenticate AS is
     * precisely what the identity-lookup hatch is for, and it is the same
     * thing the real session guard does on every request.
     *
     * Tests that assert on RLS itself should keep calling TenantContext
     * directly - this helper is only for obtaining the actor.
     */
    /**
     * Open the WITHDRAWN auto line-numbering path for a test.
     *
     * `PipingLineService::create()` no longer allocates numbers (the user's
     * ruling, 2026-09-18): a counter reading max() over surviving rows reissues
     * the number of a deleted line, and a number that has reached an isometric
     * or a PO must never come back.
     *
     * Production projects therefore supply their numbers. The numbering ENGINE
     * is still exercised by dozens of tests - composition, vocabularies,
     * counter width - and rewriting all of them to hand-build conforming
     * numbers would test the fixtures rather than the engine. So those tests
     * opt in explicitly, and the opt-in is visible in the test body rather
     * than hidden in a default.
     */
    protected function allowAutoLineNumbers(\App\Models\Project $project): void
    {
        app(TenantContext::class)->runAs(
            $project->owner_company_id,
            fn () => app(\App\Services\PipingLineService::class)
                ->configFor($project)
                ->update(['allow_auto_line_numbers' => true]),
            $project->id,
        );
    }

    protected function userByEmail(string $email): User
    {
        return app(TenantContext::class)->forIdentityLookup(
            fn (): User => User::where('email', $email)->firstOrFail()
        );
    }

    /**
     * Create identity-table fixtures (users, memberships) from a test.
     *
     * The `users` policy's WITH CHECK refuses an INSERT whose company_id is
     * not the bound tenant, and a test method binds nothing - so a bare
     * `User::create([...])` in a fixture now fails with "new row violates
     * row-level security policy". That refusal is correct; provisioning an
     * identity is exactly the operation the hatch exists for, and it is what
     * the seeder does too.
     *
     * Kept as a named helper rather than wrapping each call site so that a
     * fixture which needs the bypass has to say so out loud.
     */
    protected function provisionIdentity(callable $fn): mixed
    {
        return app(TenantContext::class)->forIdentityLookup($fn);
    }
}
