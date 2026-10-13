<?php

declare(strict_types=1);

namespace App\Filament\Resources\PunchItems\Tables;

use App\Support\JalaliDate;
use App\Support\PunchCategory;
use App\Support\PunchState;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PunchItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('epcc.punch.number'))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['dir' => 'ltr', 'style' => 'font-variant-numeric: tabular-nums']),

                TextColumn::make('subsystem.code')
                    ->label(__('epcc.punch.subsystem'))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['dir' => 'ltr']),

                TextColumn::make('category')
                    ->label(__('epcc.punch.category'))
                    ->badge()
                    // A/B/C stays Latin in both languages - it is a
                    // contractual term that appears identically on drawings,
                    // in the MC procedure and in vendor correspondence.
                    // See PunchCategory::shortLabel().
                    ->formatStateUsing(fn (string $state) => PunchCategory::shortLabel($state, app()->getLocale()))
                    ->color(fn (string $state) => match ($state) {
                        PunchCategory::A => 'danger',
                        PunchCategory::B => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('state')
                    ->label(__('epcc.punch.state'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PunchState::label($state)[app()->getLocale()] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PunchState::RAISED => 'danger',
                        PunchState::ASSIGNED => 'warning',
                        PunchState::FIXED => 'info',
                        PunchState::VERIFIED => 'success',
                        PunchState::REJECTED => 'danger',
                        PunchState::CLOSED => 'gray',
                        default => 'gray',
                    }),

                IconColumn::make('blocks_mc')
                    ->label(__('epcc.punch.blocks_mc'))
                    // Derived, not stored: an item blocks only while it is BOTH
                    // category A and still open. A stored flag would go stale
                    // the moment somebody closed the item.
                    ->getStateUsing(fn ($record) => $record->blocksMc())
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                TextColumn::make('description')
                    ->label(__('epcc.punch.description'))
                    ->getStateUsing(fn ($record) => $record->description(app()->getLocale()))
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('description_fa', 'ilike', "%{$search}%")
                        ->orWhere('description_en', 'ilike', "%{$search}%"))
                    ->wrap()
                    ->limit(70),

                TextColumn::make('responsibleCompany.code')
                    ->label(__('epcc.punch.responsible'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('company.code')
                    ->label(__('epcc.punch.raiser'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('raised_at')
                    ->label(__('epcc.punch.raised_at'))
                    ->formatStateUsing(fn ($state) => JalaliDate::format($state, app()->getLocale()))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('number')
            ->filters([
                SelectFilter::make('category')
                    ->label(__('epcc.punch.category'))
                    ->options(array_combine(PunchCategory::ALL, PunchCategory::ALL)),

                SelectFilter::make('state')
                    ->label(__('epcc.punch.state'))
                    ->options(fn () => collect([
                        PunchState::RAISED, PunchState::ASSIGNED, PunchState::FIXED,
                        PunchState::VERIFIED, PunchState::REJECTED, PunchState::CLOSED,
                    ])->mapWithKeys(fn (string $s) => [
                        $s => PunchState::label($s)[app()->getLocale()] ?? $s,
                    ])->all()),

                // The question the MC coordinator actually asks.
                Filter::make('blocking')
                    ->label(__('epcc.punch.blocks_mc'))
                    // "Open" is every state except CLOSED - VERIFIED still
                    // counts against the gate, because verified is the
                    // inspector's opinion and closed is the owner's record.
                    // See PunchState::isOpen().
                    ->query(fn (Builder $query) => $query
                        ->where('category', PunchCategory::A)
                        ->where('state', '!=', PunchState::CLOSED)),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
