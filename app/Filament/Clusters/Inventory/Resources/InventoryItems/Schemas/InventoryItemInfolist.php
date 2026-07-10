<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Item Details')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('sku')
                            ->placeholder('-'),
                        TextEntry::make('category')
                            ->badge()
                            ->color(fn ($state) => $state?->getColor()),
                        TextEntry::make('medication_display')
                            ->label('Medication')
                            ->state(fn ($record) => $record->medication?->displayName())
                            ->placeholder('-'),
                        TextEntry::make('unit.label')
                            ->label('Unit'),
                        TextEntry::make('is_active')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                    ]),
                Section::make('Description')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('description')
                            ->placeholder('No description provided.'),
                    ]),
                Section::make('Metadata')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
