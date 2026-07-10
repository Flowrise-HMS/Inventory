<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\RelationManagers;

use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockTransfer;

class StockTransferItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('inventory_item_id')
                    ->label('Item')
                    ->relationship('inventoryItem', 'name')
                    ->getOptionLabelFromRecordUsing(fn (InventoryItem $record) => $record->sku ? "{$record->name} ({$record->sku})" : $record->name)
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity_requested')
                    ->label('Quantity requested')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('inventoryItem.name')
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('inventoryItem.name')
                    ->label('Item')
                    ->searchable(),
                TextColumn::make('inventoryItem.sku')
                    ->label('SKU')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity_requested')
                    ->label('Requested'),
                TextColumn::make('quantity_shipped')
                    ->label('Shipped')
                    ->placeholder('-'),
                TextColumn::make('quantity_received')
                    ->label('Received'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === StockTransferStatus::Draft),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (): bool => $this->getOwnerRecord()->status === StockTransferStatus::Draft),
                    DeleteAction::make()
                        ->visible(fn (): bool => $this->getOwnerRecord()->status === StockTransferStatus::Draft),
                ]),
            ]);
    }

    public function getOwnerRecord(): StockTransfer
    {
        return parent::getOwnerRecord();
    }
}
