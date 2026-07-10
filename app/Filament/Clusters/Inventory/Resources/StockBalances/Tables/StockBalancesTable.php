<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\StockLocationType;

class StockBalancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('inventoryItem.name')
                    ->searchable(),
                TextColumn::make('branch.name'),
                TextColumn::make('location_type')
                    ->badge()
                    ->color(fn (StockLocationType $state): ?string => $state->getColor()),
                TextColumn::make('department.name')
                    ->toggleable(),
                TextColumn::make('lot_number')
                    ->label(__('Lot'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('expiry_date')
                    ->label(__('Expiry'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('quantity_on_hand')
                    ->sortable(),
                TextColumn::make('reorder_point'),
                TextColumn::make('unit.label'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('location_type')
                    ->options(StockLocationType::class),
                SelectFilter::make('branch_id')
                    ->relationship('branch', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
