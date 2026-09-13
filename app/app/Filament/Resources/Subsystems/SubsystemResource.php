<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subsystems;

use App\Filament\Resources\Subsystems\Pages\ListSubsystems;
use App\Filament\Resources\Subsystems\Tables\SubsystemsTable;
use App\Models\Subsystem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only admin view of the handover units and their MC gate.
 *
 * WHAT THIS SCREEN IS FOR. A subsystem is the unit that gets mechanically
 * completed and handed over, so the question an admin asks is never "what are
 * its fields" - it is "which ones can be certified, and what is standing in
 * the way". That is a derived question, answered by joining punch items, and
 * it is why this table leads with the gate rather than with the record.
 *
 * READ-ONLY, and unusually the create case is the interesting one:
 *
 *   1. Subsystem breakdown arrives as a bulk IMPORT from the project's system
 *      engineering, not as people typing rows one at a time. A single-record
 *      create form invites a hand-typed code that does not match the P&ID, and
 *      a subsystem whose code is wrong is invisible to every downstream
 *      document, punch item and certificate that references it.
 *
 *   2. `code` is the join key the whole handover chain hangs off. Editing it
 *      after punch items exist orphans them from the walkdown they belong to.
 *
 *   3. `company_id` is the contractor accountable for delivering it. Changing
 *      that does not reassign work, it rewrites who was responsible - and RLS
 *      then hides the subsystem from the team actually building it.
 *
 *   4. Deleting a subsystem with punch items or a certificate would destroy
 *      the evidence chain for a handover that may already have been invoiced.
 *
 * Certification stays in `McCertificateService`, which enforces the rule that
 * matters: no MCC while category-A items are open, unless the OWNER approves a
 * written exception. A Filament form has no opinion about any of that.
 */
class SubsystemResource extends Resource
{
    protected static ?string $model = Subsystem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function getModelLabel(): string
    {
        return __('epcc.ss.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('epcc.ss.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('epcc.nav_qaqc');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'code';
    }

    public static function table(Table $table): Table
    {
        return SubsystemsTable::configure($table);
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
            'index' => ListSubsystems::route('/'),
        ];
    }
}
