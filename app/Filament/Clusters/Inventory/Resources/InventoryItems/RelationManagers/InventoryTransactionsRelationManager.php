<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\InventoryTransactionResource;

class InventoryTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaction History';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('transaction_type')
                    ->badge()
                    ->color(fn (TransactionType $state): ?string => $state->getColor()),
                TextColumn::make('delta'),
                TextColumn::make('quantity_after'),
                TextColumn::make('unit_label_snapshot'),
                TextColumn::make('branch.name'),
                TextColumn::make('performedBy.name')
                    ->label('Performed By')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('transaction_type')
                    ->options(TransactionType::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => InventoryTransactionResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
