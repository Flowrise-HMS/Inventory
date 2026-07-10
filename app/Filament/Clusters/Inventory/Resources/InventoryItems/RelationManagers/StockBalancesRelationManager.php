<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\StockBalanceResource;

class StockBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'stockBalances';

    protected static ?string $title = 'Stock Balances';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('branch.name'),
                TextColumn::make('location_type')
                    ->badge()
                    ->color(fn (StockLocationType $state): ?string => $state->getColor()),
                TextColumn::make('department.name')
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
                ViewAction::make()
                    ->url(fn ($record): string => StockBalanceResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
