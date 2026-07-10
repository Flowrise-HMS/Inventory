<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockTransfer;

class StockTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('from_branch_id')
                    ->relationship('fromBranch', 'name')
                    ->searchable()
                    ->required(),
                Select::make('to_branch_id')
                    ->relationship('toBranch', 'name')
                    ->searchable()
                    ->required()
                    ->different('from_branch_id'),
                Textarea::make('notes')
                    ->nullable()
                    ->columnSpanFull(),
                Repeater::make('items')
                    ->relationship()
                    ->label('Items')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
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
                    ])
                    ->addActionLabel('Add item')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->required()
                    ->visible(fn (?StockTransfer $record): bool => $record === null || $record->status === StockTransferStatus::Draft),
            ]);
    }
}
