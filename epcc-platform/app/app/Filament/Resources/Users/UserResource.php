<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only admin view of the people on this tenant's projects.
 *
 * THE SECURITY WORK CAME FIRST, AND IT WAS NOT OPTIONAL.
 * Building this resource is what exposed the fact that `users` had no RLS at
 * all. Bound as EPC-PARS the database returned all nine users of all eight
 * companies - the client's representatives, the consultant's inspectors and
 * two rival contractors' engineers, names and addresses included. A Filament
 * table over that would have been a staff directory of every company on the
 * platform, published to each of them.
 *
 * The fix is migration 2026_09_12_100000_secure_identity_tables, not a
 * `->where()` in this file. That matters: the isolation this screen relies on
 * is enforced one layer below it, so a future page that forgets to filter is
 * still safe. Mutation M91 removed the policy's tenant clause and the
 * isolation tests went red while this resource was untouched - which is the
 * proof that the protection does not live here.
 *
 * WHY READ-ONLY, which for this table is a stronger argument than for the
 * others:
 *
 *   1. `is_active` IS GATE 1 of the authorization pipeline, and it is also
 *      what `canAccessPanel()` consults. An editable toggle here would let
 *      anyone who can reach /admin re-enable a terminated employee's account -
 *      re-opening every door in the product - without passing a single one of
 *      the six gates that govern every other privileged action.
 *
 *   2. `company_id` is the tenant. Changing it does not "move" a person, it
 *      re-labels every row they ever authored and hands their history to
 *      another company. RLS would then hide those rows from the colleagues who
 *      wrote them.
 *
 *   3. Roles live on the MEMBERSHIP, not the user, because authority is
 *      per-project: the same engineer may approve on one project and only view
 *      on another. A user-level role editor would quietly flatten that and
 *      encourage exactly the wrong mental model.
 *
 * Provisioning, deactivation and role changes therefore stay on the team
 * screen (`/projects/{code}/team`), which runs the pipeline and records who
 * did what. This screen answers "who is on this project, from which company,
 * with what authority" - the question an admin actually asks.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModelLabel(): string
    {
        return __('epcc.usr.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('epcc.usr.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('epcc.nav_people');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'name';
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }
}
