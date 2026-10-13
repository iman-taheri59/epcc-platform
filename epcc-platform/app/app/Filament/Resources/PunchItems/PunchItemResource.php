<?php

declare(strict_types=1);

namespace App\Filament\Resources\PunchItems;

use App\Filament\Resources\PunchItems\Pages\ListPunchItems;
use App\Filament\Resources\PunchItems\Tables\PunchItemsTable;
use App\Models\PunchItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only admin view of the punch list.
 *
 * READ-ONLY ON PURPOSE, AND MORE STRICTLY THAN THE DOCUMENT REGISTER.
 *
 * A punch item's state is not a column, it is a POSITION IN A WORKFLOW with
 * rules about who may move it: the responsible contractor fixes, anyone BUT
 * the responsible company verifies, only the employer closes. Those rules live
 * in PunchListService and PunchState::canTransition(), and they are the whole
 * value of having a punch register rather than a spreadsheet.
 *
 * A Filament form writing `state` directly would walk straight past all of
 * them - an admin could mark an item VERIFIED without anyone verifying it, on
 * a register whose entire purpose is to prove that someone did. So this
 * resource shows and filters; it does not edit.
 *
 * The MC gate reads this table (McCertificateService::blockingItems), so a
 * forged state here would also unblock a Mechanical Completion certificate.
 */
class PunchItemResource extends Resource
{
    protected static ?string $model = PunchItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getModelLabel(): string
    {
        return __('epcc.punch.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('epcc.punch.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('epcc.nav_qaqc');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'number';
    }

    public static function table(Table $table): Table
    {
        return PunchItemsTable::configure($table);
    }

    /** State transitions belong to PunchListService. See the class comment. */
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
            'index' => ListPunchItems::route('/'),
        ];
    }
}
