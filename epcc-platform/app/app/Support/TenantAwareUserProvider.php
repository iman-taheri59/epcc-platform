<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The Eloquent user provider, with every lookup wrapped in the identity hatch.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * Once `users` carried RLS, resolving the session's user became a chicken-and-
 * egg problem: the framework must read the row to discover which tenant to
 * bind, but the policy needs a bound tenant to return the row. Unresolved, the
 * user is NULL and a signed-in person is treated as a guest.
 *
 * That is not a theoretical failure. After adding the policy, every `/admin`
 * route redirected to the panel's login screen while the session was perfectly
 * valid - `BindTenant` runs LAST in the panel's middleware stack, but
 * Filament's `AuthenticateSession` and `Authenticate` resolve the user
 * earlier, so they saw nothing.
 *
 * It was invisible to the test suite because `actingAs()` injects a User
 * object straight into the guard and never touches the provider. Every panel
 * test passed against a panel a real browser could not open. Found by taking a
 * screenshot - the third defect on this project found that way and not by a
 * test.
 *
 * Fixing it at the PROVIDER rather than in one middleware means it holds for
 * every entry point that resolves an identity: the session guard, the panel,
 * `Auth::attempt`, remember-me tokens, and anything added later. Each method
 * below is the parent's behaviour, wrapped.
 *
 * Note what is NOT widened: the hatch only permits reading a user BY IDENTITY
 * (id, remember token, credentials). It is closed again before the request
 * body runs, so the pages that follow still see users through the tenant
 * policy. `IdentityIsolationTest` pins both halves.
 */
final class TenantAwareUserProvider extends EloquentUserProvider
{
    private function hatch(callable $fn): mixed
    {
        return app(TenantContext::class)->forIdentityLookup($fn);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->hatch(fn () => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return $this->hatch(fn () => parent::retrieveByToken($identifier, $token));
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        $this->hatch(function () use ($user, $token): void {
            parent::updateRememberToken($user, $token);
        });
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        return $this->hatch(fn () => parent::retrieveByCredentials($credentials));
    }
}
