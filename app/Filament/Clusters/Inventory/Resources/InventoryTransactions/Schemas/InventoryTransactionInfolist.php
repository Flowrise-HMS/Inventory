<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\TransactionType;

class InventoryTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction')
                    ->schema([
                        TextEntry::make('inventory_item.name'),
                        TextEntry::make('transaction_type')
                            ->badge()
                            ->color(fn (TransactionType $state): ?string => $state->getColor()),
                        TextEntry::make('delta'),
                        TextEntry::make('quantity_after'),
                        TextEntry::make('unit_label_snapshot'),
                        TextEntry::make('performed_by'),
                        TextEntry::make('branch.name'),
                    ]),
                Section::make('Reference')
                    ->schema([
                        TextEntry::make('reference_type'),
                        TextEntry::make('reference_id'),
                    ]),
                Section::make('Timestamps')
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
