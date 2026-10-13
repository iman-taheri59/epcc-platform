<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\JalaliDate;
use App\Support\TenantType;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('epcc.usr.name'))
                    ->searchable()
                    ->sortable(),

                // LTR and tabular: an address is a Latin identifier and reads
                // as gibberish when the RTL algorithm reorders it around the
                // @ and the dots.
                TextColumn::make('email')
                    ->label(__('epcc.usr.email'))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['dir' => 'ltr']),

                TextColumn::make('company.code')
                    ->label(__('epcc.usr.company'))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['dir' => 'ltr']),

                // The company type in words. `MC` is the reason this column is
                // spelled out rather than shown as a code: it means Management
                // Contracting / مدیریت پیمان, and readers who assume
                // "Mechanical Completion" have already caused one documented
                // round of wrong work on this project (HANDOFF §5h).
                TextColumn::make('company.tenant_type')
                    ->label(__('epcc.usr.tenant_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : TenantType::name($state, app()->getLocale()))
                    ->color('gray')
                    ->toggleable(),

                // Colleague or counterparty. Derived, never stored: it is a
                // statement about the VIEWER, so the same row is "colleague"
                // to one company and "counterparty" to another. Storing it
                // would mean storing it per-reader, which is not a column.
                TextColumn::make('relation')
                    ->label(__('epcc.usr.relation'))
                    ->badge()
                    ->getStateUsing(function (User $record): string {
                        $mine = auth()->user()?->company_id;

                        return $record->company_id === $mine
                            ? __('epcc.usr.colleague')
                            : __('epcc.usr.counterparty');
                    })
                    ->color(fn (User $record): string => $record->company_id === auth()->user()?->company_id
                        ? 'primary'
                        : 'info'),

                // How many projects this person actually holds authority on.
                // Authority is per-membership, not per-user, which is the
                // whole reason this resource does not offer a role editor.
                TextColumn::make('memberships_count')
                    ->label(__('epcc.usr.projects'))
                    ->counts('memberships')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state): string => JalaliDate::digits((string) $state, app()->getLocale()))
                    ->sortable(),

                // Gate 1 of the pipeline, shown but NOT editable. A disabled
                // account keeps its rows and its history and loses every door.
                IconColumn::make('is_active')
                    ->label(__('epcc.usr.status'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-no-symbol')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                TextColumn::make('locale')
                    ->label(__('epcc.usr.locale'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('epcc.usr.created'))
                    ->formatStateUsing(fn ($state) => JalaliDate::format($state, app()->getLocale()))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')
                    ->label(__('epcc.usr.company'))
                    ->relationship('company', 'code'),

                SelectFilter::make('is_active')
                    ->label(__('epcc.usr.is_active'))
                    ->options([
                        '1' => __('epcc.usr.active'),
                        '0' => __('epcc.usr.inactive'),
                    ]),

                // "Show me my own staff" - the common case for an admin who
                // is checking their own organisation rather than the project.
                Filter::make('colleagues')
                    ->label(__('epcc.usr.colleague'))
                    ->query(fn (Builder $query): Builder => $query->where(
                        'company_id',
                        auth()->user()?->company_id
                    )),
            ])
            ->defaultSort('name')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
