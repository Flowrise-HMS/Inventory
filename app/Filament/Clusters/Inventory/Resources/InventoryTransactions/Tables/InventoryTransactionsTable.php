<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\TransactionType;

class InventoryTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('inventoryItem.name')
                    ->searchable(),
                TextColumn::make('transaction_type')
                    ->badge()
                    ->color(fn (TransactionType $state): ?string => $state->getColor()),
                TextColumn::make('delta'),
                TextColumn::make('quantity_after'),
                TextColumn::make('unit_label_snapshot'),
                TextColumn::make('performedBy.name')
                    ->label('Performed By')
                    ->toggleable(),
                TextColumn::make('branch.name'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('transaction_type')
                    ->options(TransactionType::class),
            ])
            ->recordActions([
                Action::make('print_adjustment')
                    ->label('Print Adjustment')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.transactions.adjustment-voucher', $record))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => $record->transaction_type === TransactionType::Adjust),
                Action::make('download_adjustment')
                    ->label('Download Adjustment')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.transactions.adjustment-voucher', ['transaction' => $record, 'download' => 1]))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => $record->transaction_type === TransactionType::Adjust),
                ViewAction::make(),
            ]);
    }
}
