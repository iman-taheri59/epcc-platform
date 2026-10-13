<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Holds the current company and pushes it into PostgreSQL as a session GUC
 * so Row-Level Security can enforce tenant isolation (ARC §5.1).
 *
 * ADR-003 Annex A §3.4 named this the risky part of choosing Laravel:
 * Eloquent's connection reuse means `SET LOCAL` discipline has to be exactly
 * right, or a pooled connection carries tenant A's context into tenant B's
 * request. This class is the single place that discipline lives.
 */
final class TenantContext
{
    private ?string $companyId = null;

    private ?string $projectId = null;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * Bind the tenant for the current transaction.
     *
     * SET LOCAL is transaction-scoped, so it is discarded on commit/rollback
     * and cannot leak across requests sharing a connection. It therefore
     * REQUIRES an open transaction - outside one, PostgreSQL silently
     * discards it, which would fail open. We refuse to allow that.
     */
    public function bind(string $companyId): void
    {
        if ($this->db->transactionLevel() < 1) {
            throw new RuntimeException(
                'TenantContext::bind() requires an open transaction; '
                .'SET LOCAL outside a transaction is silently discarded and would fail open.'
            );
        }

        // set_config(name, value, is_local=true) is the parameterisable
        // equivalent of SET LOCAL. Using a bound parameter avoids building
        // SQL from a value, even though this one is a validated UUID.
        $this->db->statement("SELECT set_config('epcc.company_id', ?, true)", [$companyId]);

        $this->companyId = $companyId;
    }

    /**
     * Optionally narrow to a single project.
     *
     * Passing null clears the scope, meaning "every project this company may
     * see". That is the difference between the multi-project dashboard and a
     * single-project workspace, and it is enforced by RLS rather than by a
     * where clause someone can forget.
     */
    public function scopeToProject(?string $projectId): void
    {
        if ($this->db->transactionLevel() < 1) {
            throw new RuntimeException(
                'TenantContext::scopeToProject() requires an open transaction.'
            );
        }

        $this->db->statement("SELECT set_config('epcc.project_id', ?, true)", [$projectId ?? '']);

        $this->projectId = $projectId;
    }

    /**
     * Run a callback inside a transaction with the tenant bound, and
     * optionally scoped to one project.
     *
     * Nesting matters. `SET LOCAL` is scoped to the TRANSACTION, not to the
     * savepoint Laravel opens for a nested `transaction()` call - so a nested
     * runAs() would otherwise leave its own company/project applied to the
     * caller after it returned, silently widening or narrowing every
     * subsequent query in the outer request. When we are already inside a
     * transaction we therefore snapshot both GUCs and restore them, even if
     * the callback throws.
     */
    public function runAs(string $companyId, callable $fn, ?string $projectId = null): mixed
    {
        $nested = $this->db->transactionLevel() >= 1;

        $priorCompany = $nested ? $this->currentGuc() : null;
        $priorProject = $nested ? $this->currentProjectGuc() : null;

        return $this->db->transaction(function () use ($companyId, $projectId, $fn, $nested, $priorCompany, $priorProject): mixed {
            $this->bind($companyId);
            $this->scopeToProject($projectId);

            try {
                return $fn();
            } finally {
                if ($nested) {
                    // Restore rather than clear: the caller had a context and
                    // is entitled to get exactly it back.
                    $this->db->statement("SELECT set_config('epcc.company_id', ?, true)", [$priorCompany ?? '']);
                    $this->db->statement("SELECT set_config('epcc.project_id', ?, true)", [$priorProject ?? '']);
                    $this->companyId = $priorCompany;
                    $this->projectId = $priorProject;
                }
            }
        });
    }

    /**
     * Run a closure with permission to read the `users` table by identity
     * alone, ignoring tenant scope.
     *
     * WHY THIS IS NECESSARY AND WHY IT IS NARROW
     * ------------------------------------------
     * Authentication is a bootstrapping problem: the app must read a user row
     * to discover which company that user belongs to, so at that instant there
     * is no tenant to scope by. Under the `users` RLS policy an unset GUC
     * means "see nothing" (fail-closed, rule 3), so without an escape hatch
     * login does not leak - it simply becomes impossible, for everyone.
     *
     * This is a bypass and should be read as one. The mitigations are that it
     * is a single named thing (grep `forIdentityLookup` to find every caller),
     * it is transaction-scoped like every other GUC here, and it is cleared in
     * a `finally` so a thrown exception cannot leave it on. The test suite
     * asserts it is OFF during ordinary authenticated requests.
     *
     * Legitimate callers: the login attempt, the session guard resolving
     * `$request->user()`, and the login screen's demo-account list. Nothing
     * that renders tenant data should ever need it.
     */
    public function forIdentityLookup(callable $fn): mixed
    {
        $nested = $this->db->transactionLevel() >= 1;

        // Outside a transaction SET LOCAL is silently discarded (rule 1), so
        // the hatch would never open and the caller would get a confusing
        // "user not found" instead of a clear failure. Open one.
        if (! $nested) {
            return $this->db->transaction(fn (): mixed => $this->forIdentityLookup($fn));
        }

        // Snapshot, do not assume it was off. These calls nest in practice -
        // the seeder's user factory is itself called from inside a hatch, and
        // an inner call that cleared the GUC on the way out would silently
        // close the hatch the OUTER caller is still relying on. That bug was
        // real and quiet: the write that deactivates former@pars ran with the
        // hatch already shut, matched no rows, and left the account enabled,
        // so "a disabled account cannot hold a session" failed while the
        // account itself looked fine. runAs() already restores rather than
        // clears for exactly this reason.
        $wasOpen = $this->identityLookupIsOpen();

        $this->db->statement("SELECT set_config('epcc.identity_lookup', 'on', true)");

        try {
            return $fn();
        } finally {
            $this->db->statement(
                "SELECT set_config('epcc.identity_lookup', ?, true)",
                [$wasOpen ? 'on' : '']
            );
        }
    }

    /** Is the identity-lookup bypass currently open? Used by tests. */
    public function identityLookupIsOpen(): bool
    {
        $v = $this->db->selectOne("SELECT current_setting('epcc.identity_lookup', true) AS v");

        return ($v->v ?? '') === 'on';
    }

    public function companyId(): ?string
    {
        return $this->companyId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    /** What PostgreSQL currently believes the tenant is. Used by tests. */
    public function currentGuc(): ?string
    {
        $v = $this->db->selectOne("SELECT current_setting('epcc.company_id', true) AS v");

        return ($v->v ?? '') === '' ? null : $v->v;
    }

    /** What PostgreSQL currently believes the project scope is. */
    public function currentProjectGuc(): ?string
    {
        $v = $this->db->selectOne("SELECT current_setting('epcc.project_id', true) AS v");

        return ($v->v ?? '') === '' ? null : $v->v;
    }
}
