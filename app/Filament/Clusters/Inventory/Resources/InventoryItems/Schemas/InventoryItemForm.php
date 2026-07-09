<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\InventoryItemCategory;

class InventoryItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->debounce(200),
                TextInput::make('sku')
                    ->maxLength(100)
                    ->nullable(),
                Select::make('category')
                    ->options(InventoryItemCategory::class)
                    ->required(),
                Select::make('medication_id')
                    ->relationship('medication', 'displayName')
                    ->searchable()
                    ->nullable(),
                Select::make('unit_id')
                    ->relationship('unit', 'label')
                    ->searchable()
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
                Textarea::make('description')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
