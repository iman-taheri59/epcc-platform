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
