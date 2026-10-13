<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the request in a transaction and binds the AUTHENTICATED user's
 * company so RLS applies for its whole lifetime.
 *
 * Identity now comes from the session, not from the URL. That is the point of
 * the auth work: previously `?as=EPC-PARS` let anyone assume any tenant, and
 * only RLS-plus-honesty stood between a visitor and another company's data.
 *
 * The transaction is mandatory: TenantContext::bind() uses SET LOCAL, which
 * PostgreSQL silently discards outside a transaction - i.e. it would fail
 * OPEN. See docs/Spike-RLS-Results.md rule 1.
 */
final class BindTenant
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Resolving the session's user is itself a read of `users`, which now
        // carries RLS - and at this instant no tenant is bound, so the policy
        // would (correctly) match nothing and every signed-in person would be
        // treated as a guest. This is the bootstrapping problem the
        // identity-lookup hatch exists for: we must read the row to learn
        // which tenant to bind. See TenantContext::forIdentityLookup().
        $user = $this->tenant->forIdentityLookup(fn () => $request->user());

        if ($user === null) {
            // Unauthenticated: bind nothing. RLS then shows nothing, which is
            // the correct fail-closed behaviour rather than an error.
            return $next($request);
        }

        return DB::transaction(function () use ($user, $request, $next): Response {
            $this->tenant->bind($user->company_id);

            return $next($request);
        });
    }
}
