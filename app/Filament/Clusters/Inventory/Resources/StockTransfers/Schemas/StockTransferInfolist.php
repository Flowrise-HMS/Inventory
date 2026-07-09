<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\StockTransferStatus;

class StockTransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Stock Transfer')
                    ->schema([
                        TextEntry::make('transfer_number'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (StockTransferStatus $state): ?string => $state->getColor()),
                        TextEntry::make('from_branch.name'),
                        TextEntry::make('to_branch.name'),
                    ]),
                Section::make('Items')
                    ->schema([
                        TextEntry::make('items')
                            ->getStateUsing(function ($record): string {
                                return $record->items->map(fn ($item) => sprintf(
                                    '%s — Req: %d, Shipped: %d, Received: %d',
                                    $item->inventoryItem?->name ?? 'Unknown',
                                    $item->quantity_requested,
                                    $item->quantity_shipped ?? 0,
                                    $item->quantity_received ?? 0
                                ))->implode("\n");
                            }),
                    ]),
                Section::make('Workflow')
                    ->schema([
                        TextEntry::make('shippedBy.name'),
                        TextEntry::make('shipped_at')
                            ->dateTime(),
                        TextEntry::make('receivedBy.name'),
                        TextEntry::make('received_at')
                            ->dateTime(),
                        TextEntry::make('closed_reason'),
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
