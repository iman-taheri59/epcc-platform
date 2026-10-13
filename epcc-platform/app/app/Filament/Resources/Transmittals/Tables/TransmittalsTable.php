<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transmittals\Tables;

use App\Models\Transmittal;
use App\Support\JalaliDate;
use App\Support\TransmittalPurpose;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransmittalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('epcc.tr.number'))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['dir' => 'ltr', 'style' => 'font-variant-numeric: tabular-nums']),

                TextColumn::make('kind')
                    ->label(__('epcc.tr.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Transmittal::VENDOR
                        ? __('epcc.tr.kind_vendor')
                        : __('epcc.tr.kind_contractor'))
                    ->color(fn (string $state) => $state === Transmittal::VENDOR ? 'info' : 'primary')
                    ->sortable(),

                TextColumn::make('subject')
                    ->label(__('epcc.tr.subject'))
                    ->getStateUsing(fn ($record) => app()->getLocale() === 'fa'
                        ? $record->subject_fa
                        : $record->subject_en)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('subject_fa', 'ilike', "%{$search}%")
                        ->orWhere('subject_en', 'ilike', "%{$search}%"))
                    ->wrap()
                    ->limit(55),

                TextColumn::make('purpose')
                    ->label(__('epcc.tr.purpose'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TransmittalPurpose::label($state, app()->getLocale())),

                TextColumn::make('status')
                    ->label(__('epcc.tr.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Transmittal::ISSUED
                        ? __('epcc.tr.status_issued')
                        : __('epcc.tr.status_draft'))
                    ->color(fn (string $state) => $state === Transmittal::ISSUED ? 'success' : 'gray'),

                TextColumn::make('items_count')
                    ->label(__('epcc.tr.items'))
                    ->counts('items')
                    ->alignCenter(),

                /*
                 * Overdue is DERIVED, never stored: a letter is overdue when it
                 * is issued, past its due date, and some recipient has still
                 * not acknowledged. A stored flag would be wrong the moment
                 * the last recipient acknowledges, and wrong again at midnight.
                 */
                IconColumn::make('overdue')
                    ->label(__('epcc.tr.overdue'))
                    ->getStateUsing(fn ($record) => $record->isIssued()
                        && $record->due_at !== null
                        && $record->due_at->isPast()
                        && $record->recipients->contains(fn ($r) => $r->acknowledged_at === null))
                    ->boolean()
                    ->trueIcon('heroicon-o-clock')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                TextColumn::make('vendor.code')
                    ->label(__('epcc.tr.vendor'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('vendor_reference')
                    ->label(__('epcc.tr.vendor_reference'))
                    ->placeholder('—')
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(isToggledHiddenByDefault: true),

                // The RECORDING company, which for a vendor letter is the EPC
                // that logged it - not the vendor that sent it.
                TextColumn::make('company.code')
                    ->label(__('epcc.tr.recorder'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('issued_at')
                    ->label(__('epcc.tr.issued_at'))
                    // Jalali, like every other date in the product. Filament's
                    // ->dateTime() renders Gregorian, which on a Persian
                    // submission log is the difference between a letter being
                    // on time and being late.
                    ->formatStateUsing(fn ($state) => JalaliDate::format($state, app()->getLocale()))
                    ->sortable(),
            ])
            ->defaultSort('number')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('epcc.tr.kind'))
                    ->options([
                        Transmittal::CONTRACTOR => __('epcc.tr.kind_contractor'),
                        Transmittal::VENDOR => __('epcc.tr.kind_vendor'),
                    ]),

                SelectFilter::make('status')
                    ->label(__('epcc.tr.status'))
                    ->options([
                        Transmittal::DRAFT => __('epcc.tr.status_draft'),
                        Transmittal::ISSUED => __('epcc.tr.status_issued'),
                    ]),

                Filter::make('overdue')
                    ->label(__('epcc.tr.overdue'))
                    ->query(fn (Builder $query) => $query
                        ->where('status', Transmittal::ISSUED)
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', now())
                        ->whereHas('recipients', fn (Builder $r) => $r->whereNull('acknowledged_at'))),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
