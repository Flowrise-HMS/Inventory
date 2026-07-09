<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\PurchaseOrderStatus;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Purchase Order')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('po_number'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (PurchaseOrderStatus $state): string => $state->getColor()),
                        TextEntry::make('supplier.name'),
                        TextEntry::make('branch.name'),
                        TextEntry::make('ordered_at')
                            ->dateTime(),
                        TextEntry::make('expected_delivery_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('inventoryItem.name')
                                    ->label('Item'),
                                TextEntry::make('quantity_ordered')
                                    ->label('Ordered'),
                                TextEntry::make('quantity_received')
                                    ->label('Received'),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Receipts')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('receipts')
                            ->schema([
                                TextEntry::make('received_at')
                                    ->dateTime(),
                                TextEntry::make('received_by'),
                                TextEntry::make('notes')
                                    ->placeholder('-'),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record): bool => $record->receipts()->exists()),
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
