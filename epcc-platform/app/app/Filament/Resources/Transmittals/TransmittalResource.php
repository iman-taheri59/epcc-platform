<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transmittals;

use App\Filament\Resources\Transmittals\Pages\ListTransmittals;
use App\Filament\Resources\Transmittals\Tables\TransmittalsTable;
use App\Models\Transmittal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only admin view of BOTH transmittal streams.
 *
 * ONE RESOURCE, NOT TWO. The hand-written UI splits contractor and vendor
 * letters into separate screens on purpose - a document controller working the
 * contractual submission log does not want supply-chain letters mixed into
 * their numbering. The admin view has the opposite job: "show me every letter
 * on this project", with `kind` as a filter. Same table, different question.
 *
 * READ-ONLY, and the reason is sharper than for the punch list.
 *
 *   1. An ISSUED transmittal is IMMUTABLE. That is not a UI convention, it is
 *      the point of a transmittal: it evidences what was sent, to whom, on
 *      what date. `TransmittalService::issue()` freezes it, and the register
 *      refuses edits afterwards. A Filament form would write straight through
 *      that with an Eloquent update.
 *
 *   2. `number` comes from a PER-STREAM counter (TR / VTR) taken under a row
 *      lock on the project. Retyping it collides with the unique index, and
 *      worse, silently perforates a contractual series - the gap in
 *      PH-14-TR-0007 is the question an auditor asks first.
 *
 *   3. Items carry a SNAPSHOT of the revision as sent (number, revision,
 *      state, suitability). Editing them would rewrite history: the letter
 *      would claim to have transmitted something it did not.
 *
 * So: list, filter, inspect. Issuing, acknowledging and rolling up stay in
 * TransmittalService, which is where the rules are.
 */
class TransmittalResource extends Resource
{
    protected static ?string $model = Transmittal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public static function getModelLabel(): string
    {
        return __('epcc.tr.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('epcc.tr.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('epcc.nav.engineering');
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return 'number';
    }

    public static function table(Table $table): Table
    {
        return TransmittalsTable::configure($table);
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
            'index' => ListTransmittals::route('/'),
        ];
    }
}
