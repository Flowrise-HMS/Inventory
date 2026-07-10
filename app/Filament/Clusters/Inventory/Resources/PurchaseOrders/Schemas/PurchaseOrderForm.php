<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\PurchaseOrder;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('branch_id')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DateTimePicker::make('ordered_at')
                    ->required()
                    ->default(now()),
                DateTimePicker::make('expected_delivery_at')
                    ->nullable(),

                Repeater::make('items')
                    ->relationship()
                    ->label('Items')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
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
                    ])
                    ->addActionLabel('Add item')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->required()
                    ->visible(fn (?PurchaseOrder $record): bool => $record === null || $record->status === PurchaseOrderStatus::Draft),

                Textarea::make('notes')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
