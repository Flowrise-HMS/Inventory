<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\Store;

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
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('store_id', null))
                    ->required(),
                Select::make('store_id')
                    ->label('Store')
                    ->helperText('Leave empty to request from the branch main store.')
                    ->options(fn (Get $get): array => filled($get('branch_id'))
                        ? Store::query()->forBranch((string) $get('branch_id'))->active()->orderByDesc('is_main')->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable()
                    ->nullable()
                    ->disabled(fn (?Requisition $record): bool => $record !== null && $record->status !== RequisitionStatus::Pending),
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
