<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subsystems\Tables;

use App\Models\McCertificate;
use App\Models\Subsystem;
use App\Support\JalaliDate;
use App\Support\PunchCategory;
use App\Support\PunchState;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubsystemsTable
{
    /**
     * Categories that block Mechanical Completion, asked of the domain rather
     * than hardcoded as 'A'.
     *
     * The rule "category A blocks MC" lives in PunchCategory. Repeating the
     * letter here would mean a future change to that rule silently leaves this
     * table asserting the old one - and this table is where somebody decides
     * whether a subsystem can be handed over.
     *
     * @return list<string>
     */
    private static function blockingCategories(): array
    {
        return array_values(array_filter(
            PunchCategory::ALL,
            fn (string $c): bool => PunchCategory::blocksMechanicalCompletion($c),
        ));
    }

    /**
     * States that count as open. Derived through PunchState::isOpen() for the
     * same reason - and note VERIFIED is one of them: verified is the
     * inspector's opinion, closed is the owner's record, and only the latter
     * clears the gate.
     *
     * @return list<string>
     */
    private static function openStates(): array
    {
        $all = [
            PunchState::RAISED,
            PunchState::ASSIGNED,
            PunchState::FIXED,
            PunchState::VERIFIED,
            PunchState::CLOSED,
            PunchState::REJECTED,
        ];

        return array_values(array_filter($all, fn (string $s): bool => PunchState::isOpen($s)));
    }

    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Counts are computed by the DATABASE, not by Subsystem's helper
             * methods.
             *
             * `openBlockingItems()` and `closurePercent()` both walk
             * `$this->punchItems`, which is correct for one record on a detail
             * page and quadratic on a register: each row would issue its own
             * query, and the count would be computed in PHP over hydrated
             * models. The gate state is the reason this screen exists, so it
             * has to survive a real project's few thousand subsystems.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount([
                    'punchItems',
                    'punchItems as blocking_count' => fn (Builder $q): Builder => $q
                        ->whereIn('category', self::blockingCategories())
                        ->whereIn('state', self::openStates()),
                    'punchItems as closed_count' => fn (Builder $q): Builder => $q
                        ->whereNotIn('state', self::openStates()),
                ])
                ->with(['certificate', 'facility', 'area']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('epcc.ss.code'))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['dir' => 'ltr', 'style' => 'font-variant-numeric: tabular-nums']),

                /*
                 * Since ADR-004 the subsystem code is only unique WITHIN a
                 * facility: PL-01/SS-01 and PL-02/SS-01 are two different
                 * subsystems and both are legal. A register that showed the
                 * bare code would render them as indistinguishable duplicate
                 * rows, so the facility travels next to the code. It is not
                 * toggleable for that reason - hiding it would make the code
                 * column ambiguous rather than merely terser.
                 */
                TextColumn::make('facility.code')
                    ->label(__('epcc.ss.facility'))
                    ->sortable()
                    ->searchable()
                    ->extraAttributes(['dir' => 'ltr']),

                /*
                 * Area is nullable by design: a facility may use areas and
                 * still have subsystems that sit directly under it, and a
                 * small utility block may have no areas at all. An em dash
                 * reads as "deliberately none" where a blank cell reads as
                 * missing data.
                 */
                TextColumn::make('area.code')
                    ->label(__('epcc.ss.area'))
                    ->placeholder("\u{2014}")
                    ->toggleable()
                    ->extraAttributes(['dir' => 'ltr']),

                TextColumn::make('name')
                    ->label(__('epcc.ss.name'))
                    ->getStateUsing(fn (Subsystem $record): string => $record->name(app()->getLocale()))
                    // Both language columns are searchable: a document
                    // controller types Persian, an expat engineer types
                    // English, and neither should get an empty result.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name_fa', 'ilike', "%{$search}%")
                        ->orWhere('name_en', 'ilike', "%{$search}%"))
                    ->wrap(),

                TextColumn::make('discipline')
                    ->label(__('epcc.ss.discipline'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                /*
                 * THE GATE. Derived on read, never stored: a flag written at
                 * walkdown time is wrong the moment somebody closes the last
                 * A-item, and "can this be handed over" is precisely the
                 * question nobody should be answering from a stale column.
                 */
                TextColumn::make('gate')
                    ->label(__('epcc.ss.gate'))
                    ->badge()
                    ->getStateUsing(fn (Subsystem $record): string => ((int) $record->blocking_count) > 0
                        ? __('epcc.ss.blocked')
                        : __('epcc.ss.clear'))
                    ->color(fn (Subsystem $record): string => ((int) $record->blocking_count) > 0
                        ? 'danger'
                        : 'success'),

                TextColumn::make('blocking_count')
                    ->label(__('epcc.ss.blocking'))
                    ->alignCenter()
                    ->formatStateUsing(fn ($state): string => JalaliDate::digits((string) $state, app()->getLocale()))
                    ->color(fn ($state): string => ((int) $state) > 0 ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('punch_items_count')
                    ->label(__('epcc.ss.punch'))
                    ->alignCenter()
                    ->formatStateUsing(fn ($state): string => JalaliDate::digits((string) $state, app()->getLocale()))
                    ->sortable(),

                /*
                 * Closure percentage, with the empty case spelled out.
                 *
                 * Subsystem::closurePercent() returns 100 when there are no
                 * punch items, which is right arithmetically and dangerous on
                 * a handover register: "100%" next to a subsystem nobody has
                 * walked down yet reads as finished. It is the opposite. So an
                 * un-inspected subsystem says so in words.
                 */
                TextColumn::make('closure')
                    ->label(__('epcc.ss.closure'))
                    ->getStateUsing(function (Subsystem $record): string {
                        $total = (int) $record->punch_items_count;

                        if ($total === 0) {
                            return __('epcc.ss.not_walked');
                        }

                        $percent = (int) round(((int) $record->closed_count) / $total * 100);

                        return JalaliDate::digits((string) $percent, app()->getLocale())
                            .(app()->getLocale() === 'fa' ? '٪' : '%');
                    })
                    ->badge()
                    ->color(fn (Subsystem $record): string => ((int) $record->punch_items_count) === 0
                        ? 'warning'
                        : 'gray'),

                TextColumn::make('certificate.number')
                    ->label(__('epcc.ss.cert'))
                    ->placeholder(__('epcc.ss.no_cert'))
                    ->badge()
                    // The column is `state`, not `status`. Reading it as
                    // `status` returns NULL silently and renders an empty
                    // badge - the same class of defect as the documents table
                    // binding `state` instead of `cde_state` (M81).
                    ->color(fn (Subsystem $record): string => match ($record->certificate?->state) {
                        McCertificate::ISSUED => 'success',
                        McCertificate::DRAFT => 'warning',
                        default => 'gray',
                    })
                    ->extraAttributes(['dir' => 'ltr']),

                TextColumn::make('company.code')
                    ->label(__('epcc.ss.company'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(),

                TextColumn::make('project.code')
                    ->label(__('epcc.ss.project'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('epcc.ss.created'))
                    ->formatStateUsing(fn ($state) => JalaliDate::format($state, app()->getLocale()))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('discipline')
                    ->label(__('epcc.ss.discipline'))
                    ->options(fn (): array => Subsystem::query()
                        ->distinct()
                        ->orderBy('discipline')
                        ->pluck('discipline', 'discipline')
                        ->all()),

                // The one filter a handover meeting actually runs.
                Filter::make('blocked')
                    ->label(__('epcc.ss.only_blocked'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'punchItems',
                        fn (Builder $q): Builder => $q
                            ->whereIn('category', self::blockingCategories())
                            ->whereIn('state', self::openStates()),
                    )),
            ])
            ->defaultSort('code')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
