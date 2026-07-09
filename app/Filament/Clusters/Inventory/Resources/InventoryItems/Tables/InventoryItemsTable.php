<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\InventoryItemCategory;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku'),
                TextColumn::make('category')
                    ->badge()
                    ->color(fn (InventoryItemCategory $state): string => $state->getColor()),
                TextColumn::make('medication.display_name')
                    ->label('Medication')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('unit.label')
                    ->label('Unit'),
                TextColumn::make('is_active')
                    ->badge()
                    ->colors([
                        'success' => true,
                        'danger' => false,
                    ])
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(InventoryItemCategory::class),
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                Action::make('print_stock_card')
                    ->label('Print Stock Card')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.items.stock-card', $record))
                    ->openUrlInNewTab(),
                Action::make('download_stock_card')
                    ->label('Download Stock Card')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.items.stock-card', ['item' => $record, 'download' => 1]))
                    ->openUrlInNewTab(),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
