<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\StockLocationType;

class StockBalanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Stock Balance')
                    ->schema([
                        TextEntry::make('inventory_item.name'),
                        TextEntry::make('branch.name'),
                        TextEntry::make('location_type')
                            ->badge()
                            ->color(fn (StockLocationType $state): ?string => $state->getColor()),
                        TextEntry::make('department.name'),
                        TextEntry::make('quantity_on_hand'),
                        TextEntry::make('reorder_point'),
                        TextEntry::make('unit.label'),
                    ]),
                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
