<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\RelationManagers;

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
use Modules\Core\Filament\Tables\Columns\CurrencyColumn;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\PurchaseOrder;

class PurchaseOrderItemsRelationManager extends RelationManager
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
                TextInput::make('quantity_ordered')
                    ->label('Quantity ordered')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('expected_unit_price')
                    ->label('Expected unit price')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),
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
                TextColumn::make('quantity_ordered')
                    ->label('Ordered'),
                TextColumn::make('quantity_received')
                    ->label('Received'),
                CurrencyColumn::make('expected_unit_price')
                    ->label('Expected unit price')
                    ->default(0),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === PurchaseOrderStatus::Draft),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (): bool => $this->getOwnerRecord()->status === PurchaseOrderStatus::Draft),
                    DeleteAction::make()
                        ->visible(fn (): bool => $this->getOwnerRecord()->status === PurchaseOrderStatus::Draft),
                ]),
            ]);
    }

    public function getOwnerRecord(): PurchaseOrder
    {
        return parent::getOwnerRecord();
    }
}
