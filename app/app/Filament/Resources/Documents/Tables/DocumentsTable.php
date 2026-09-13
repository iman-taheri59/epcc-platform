<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Tables;

use App\Support\CdeState;
use App\Support\JalaliDate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The document register as an admin table.
 *
 * WHAT WAS CHANGED FROM THE GENERATED SCAFFOLD, AND WHY
 * ----------------------------------------------------
 * `make:filament-resource --generate` reads the schema and emits a column per
 * database column. That is a faithful rendering of the TABLE and a useless
 * rendering of the DOMAIN: it led with three UUIDs (id, project.id,
 * company.id), showed both title columns at once regardless of language, and
 * offered no filters.
 *
 * A document controller identifies a document by its NUMBER. The UUID is an
 * implementation detail they should never have to read, so it is gone - not
 * merely toggled off, because a hidden column is still a column somebody can
 * turn on and then paste into an email.
 *
 * DELETE IS DELIBERATELY ABSENT. The generated scaffold shipped a
 * DeleteBulkAction. A controlled document register does not delete: revisions
 * supersede and states archive, which is the whole point of ISO 19650 and the
 * reason DocumentRegister archives rather than overwrites. A bulk-delete
 * button on this table would let one mis-click destroy the audit trail the
 * product exists to keep.
 */
class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('epcc.doc.number'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    // The identifier is Latin even in Persian prose; forcing
                    // LTR stops the bidi algorithm reordering the segments.
                    ->extraAttributes(['dir' => 'ltr', 'style' => 'font-variant-numeric: tabular-nums']),

                TextColumn::make('title')
                    ->label(__('epcc.doc.title'))
                    ->getStateUsing(fn ($record) => app()->getLocale() === 'fa'
                        ? $record->title_fa
                        : $record->title_en)
                    // Both titles are searchable even though one is shown, so
                    // an English search still finds a record displayed in
                    // Persian. On a bilingual project people search in
                    // whichever language the document was named in.
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where('title_fa', 'ilike', "%{$search}%")
                        ->orWhere('title_en', 'ilike', "%{$search}%"))
                    ->wrap()
                    ->limit(60),

                TextColumn::make('discipline')
                    ->label(__('epcc.doc.discipline'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('doc_type')
                    ->label(__('epcc.doc.type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('currentRevision.cde_state')
                    ->label(__('epcc.doc.state'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === null
                        ? '—'
                        : CdeState::label($state, app()->getLocale()))
                    ->color(fn (?string $state) => match ($state) {
                        CdeState::WIP => 'gray',
                        CdeState::SHARED => 'warning',
                        CdeState::PUBLISHED => 'success',
                        CdeState::ARCHIVED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('currentRevision.revision')
                    ->label(__('epcc.doc.revision'))
                    ->badge()
                    ->extraAttributes(['dir' => 'ltr']),

                TextColumn::make('company.code')
                    ->label(__('epcc.doc.owner'))
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('epcc.doc.created'))
                    ->formatStateUsing(fn ($state) => JalaliDate::format($state, app()->getLocale()))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('number')
            ->filters([
                SelectFilter::make('discipline')
                    ->label(__('epcc.doc.discipline'))
                    ->options(fn () => \App\Models\Document::query()
                        ->distinct()
                        ->orderBy('discipline')
                        ->pluck('discipline', 'discipline')
                        ->all()),

                SelectFilter::make('doc_type')
                    ->label(__('epcc.doc.type'))
                    ->options(fn () => \App\Models\Document::query()
                        ->distinct()
                        ->orderBy('doc_type')
                        ->pluck('doc_type', 'doc_type')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                // Intentionally empty - see the class comment on why there is
                // no bulk delete on a controlled register.
                BulkActionGroup::make([]),
            ]);
    }
}
