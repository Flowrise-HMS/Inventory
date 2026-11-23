<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Models\StockTake;

class StockTakesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['store', 'startedBy'])
                ->withCount([
                    'lines',
                    'lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_quantity'),
                    'lines as variance_lines_count' => fn ($q) => $q->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '!=', 'system_quantity'),
                ]))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->weight('medium'),
                TextColumn::make('store.name')->label(__('Store')),
                TextColumn::make('status')->badge(),
                TextColumn::make('progress')
                    ->label(__('Counted'))
                    ->state(fn (StockTake $record): string => sprintf('%d / %d', $record->counted_lines_count, $record->lines_count)),
                TextColumn::make('variance_lines_count')
                    ->label(__('Differences'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->dateTime()
                    ->sortable()
                    ->description(fn (StockTake $record): ?string => $record->startedBy?->name),
                TextColumn::make('approved_at')
                    ->label(__('Approved'))
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(StockTakeStatus::class),
                SelectFilter::make('store_id')->label(__('Store'))->relationship('store', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
