<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;

class RequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('requestor_id')
                    ->relationship('requestor', 'name')
                    ->searchable()
                    ->required(),
                Select::make('department_id')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->required(),
                Select::make('branch_id')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->required(),
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
                    ->visible(fn (?Requisition $record): bool => $record === null || $record->status === RequisitionStatus::Pending),

                Textarea::make('notes')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
