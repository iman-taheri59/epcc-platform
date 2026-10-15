<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuthorizationDenial;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * The ARC §5 authorization pipeline.
 *
 * Gates run in STRICT ORDER and the first failure wins. Order matters for
 * information disclosure as much as for logic: a user who is not a member of a
 * project must not be able to distinguish "no such capability" from "capability
 * exists but you lack the role", because that difference leaks the shape of
 * another company's project.
 *
 *   1. AUTHENTICATION  - handled by Laravel's auth middleware
 *   2. ENTITLEMENT     - does the caller's COMPANY plan include this module?
 *   3. MEMBERSHIP      - is the user on this project at all?
 *   4. RBAC            - does any assigned role grant the atomic capability?
 *   5. ABAC/VISIBILITY - scope filters and the cross-company matrix
 *   6. SoD             - conflicting duties (write operations only)
 *
 * Every denial is recorded (SRS NFR-SEC-06). Denials are deliberately NOT
 * tenant-scoped in storage: a cross-tenant probe is exactly what a security
 * operator needs to see.
 */
final class AuthorizationPipeline
{
    /**
     * Duty pairs that may not be exercised by the same person on one object.
     * Gate 6. Keyed by capability, listing capabilities that conflict with it.
     *
     * @var array<string,list<string>>
     */
    private const SOD_CONFLICTS = [
        'cert.approve' => ['cert.submit'],
        'cert.submit' => ['cert.approve'],
    ];

    /** Which module each capability belongs to, for gate 2. */
    private const CAPABILITY_MODULE = [
        'cert.' => 'cost',
        'iwp.' => 'awp',
        'cwp.' => 'awp',
        'doc.' => 'doc',
        'punch.' => 'qaqc',
        'project.' => 'core',
        'engineering.' => 'core',
    ];

    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * Run every gate. Returns null when allowed, or a Denial describing the
     * first gate that refused.
     */
    public function check(
        User $user,
        string $capability,
        ?Project $project = null,
        array $context = [],
    ): ?Denial {
        // ---- Gate 1: AUTHENTICATION -------------------------------------
        if (! $user->is_active) {
            return $this->deny($user, $project, 'AUTHENTICATION', 'ACCOUNT_DISABLED', $capability);
        }

        // ---- Gate 2: ENTITLEMENT ----------------------------------------
        $plan = $user->company?->plan;

        if ($plan === null) {
            return $this->deny($user, $project, 'ENTITLEMENT', 'NO_PLAN', $capability);
        }

        $module = $this->moduleFor($capability);

        if ($module !== null && ! $plan->hasModule($module)) {
            return $this->deny($user, $project, 'ENTITLEMENT', 'ENTITLEMENT_MISSING', $capability);
        }

        // Tenant-level request (no project): gates 3 and 5-6 do not apply,
        // but RBAC still must. Returning early here would have meant that
        // ANY user at an entitled company could perform a tenant-level
        // capability such as `project.create` regardless of their role.
        if ($project === null) {
            if (! $this->anyRoleGrants($user, $capability)) {
                return $this->deny($user, null, 'RBAC', 'CAPABILITY_NOT_GRANTED', $capability);
            }

            return null;
        }

        // ---- Gate 3: MEMBERSHIP -----------------------------------------
        $membership = $user->membershipFor($project->id);

        if ($membership === null) {
            return $this->deny($user, $project, 'MEMBERSHIP', 'NOT_PROJECT_MEMBER', $capability);
        }

        // The plan must also permit the pack this project runs on. A company
        // on ISO-only cannot be dragged onto an AWP project by an invite.
        if (! $plan->allowsPack($project->standards_pack)) {
            return $this->deny($user, $project, 'ENTITLEMENT', 'PACK_NOT_ENTITLED', $capability);
        }

        // ---- Gate 4: RBAC ------------------------------------------------
        $granted = false;

        foreach (Role::whereIn('code', $membership->role_codes ?? [])->get() as $role) {
            if ($role->grants($capability)) {
                $granted = true;
                break;
            }
        }

        if (! $granted) {
            return $this->deny($user, $project, 'RBAC', 'CAPABILITY_NOT_GRANTED', $capability);
        }

        // ---- Gate 5: ABAC / VISIBILITY ------------------------------------
        $discipline = $context['discipline'] ?? null;
        $scope = $membership->discipline_scope;

        if ($discipline !== null && is_array($scope) && ! in_array($discipline, $scope, true)) {
            return $this->deny($user, $project, 'ABAC', 'SCOPE_EXCLUDED', $capability);
        }

        // Delegation of authority: an amount above the user's limit is refused
        // even when the capability is granted.
        $amount = $context['amount'] ?? null;

        if ($amount !== null && $membership->authority_limit !== null
            && (float) $amount > (float) $membership->authority_limit) {
            return $this->deny($user, $project, 'ABAC', 'AUTHORITY_EXCEEDED', $capability);
        }

        // ---- Gate 6: SoD ---------------------------------------------------
        $alreadyDid = $context['actor_history'] ?? [];

        foreach (self::SOD_CONFLICTS[$capability] ?? [] as $conflict) {
            if (in_array($conflict, $alreadyDid, true)) {
                return $this->deny($user, $project, 'SOD', 'SOD_CONFLICT', $capability);
            }
        }

        return null;
    }

    public function allows(User $user, string $capability, ?Project $project = null, array $context = []): bool
    {
        return $this->check($user, $capability, $project, $context) === null;
    }

    /**
     * Does ANY role the user holds - on any project - grant this capability?
     *
     * Used only for tenant-level checks, where there is no project and hence
     * no single membership to consult. Deliberately permissive in scope but
     * still a real check: a site engineer holds no `project.create` anywhere,
     * so they are refused.
     */
    private function anyRoleGrants(User $user, string $capability): bool
    {
        $codes = $user->memberships()
            ->get()
            ->flatMap(fn ($m): array => $m->role_codes ?? [])
            ->unique()
            ->all();

        if ($codes === []) {
            return false;
        }

        foreach (Role::whereIn('code', $codes)->get() as $role) {
            if ($role->grants($capability)) {
                return true;
            }
        }

        return false;
    }

    private function moduleFor(string $capability): ?string
    {
        foreach (self::CAPABILITY_MODULE as $prefix => $module) {
            if (str_starts_with($capability, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    private function deny(
        User $user,
        ?Project $project,
        string $gate,
        string $reason,
        string $capability,
    ): Denial {
        // Audit writes must never break the request they are describing.
        try {
            AuthorizationDenial::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'project_id' => $project?->id,
                'gate' => $gate,
                'reason' => $reason,
                'capability' => $capability,
                'path' => request()?->path(),
            ]);
        } catch (\Throwable) {
            // Swallowed deliberately: a full audit table must not turn a 403
            // into a 500. Monitoring covers audit-write failures separately.
        }

        return new Denial($gate, $reason, $capability);
    }
}
