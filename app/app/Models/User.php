<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A user belongs to exactly ONE company - its tenant.
 *
 * This is the whole point of the auth work: `epcc.company_id` is now derived
 * from the authenticated session instead of a query string, so RLS enforces
 * the identity the user actually logged in as.
 */
final class User extends Authenticatable implements FilamentUser
{
    use HasUuid, Notifiable;

    protected $fillable = ['company_id', 'name', 'email', 'password', 'locale', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * May this user open a Filament panel?
     *
     * Filament asks this on every panel request. Without it, Filament's own
     * default refuses everyone outside the `local` environment - which is why
     * the very first panel test got a 403 rather than a dashboard.
     *
     * The rule mirrors gate 1 of the authorization pipeline: a deactivated
     * account keeps its rows and its history but loses every door. It is NOT
     * a substitute for the six-gate pipeline - that still runs per action.
     * This only decides whether the admin shell opens at all.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    public function membershipFor(string $projectId): ?ProjectMembership
    {
        return $this->memberships()->where('project_id', $projectId)->first();
    }

    /** Roles granted on a project, resolved from the membership's role codes. */
    public function rolesOn(string $projectId): array
    {
        $membership = $this->membershipFor($projectId);

        if ($membership === null) {
            return [];
        }

        return Role::whereIn('code', $membership->role_codes ?? [])->get()->all();
    }
}
