<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Inventory\Enums\RequisitionStatus;

class RequisitionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Requisition')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('requisition_number'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (RequisitionStatus $state): string => $state->getColor()),
                        TextEntry::make('requestor.name')
                            ->label('Requestor'),
                        TextEntry::make('department.name')
                            ->label('Department'),
                        TextEntry::make('branch.name')
                            ->label('Branch'),
                        TextEntry::make('notes')
                            ->placeholder('No notes.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Workflow')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('approvedBy.name')
                            ->label('Approved By')
                            ->placeholder('-'),
                        TextEntry::make('approved_at')
                            ->label('Approved At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('declinedBy.name')
                            ->label('Declined By')
                            ->placeholder('-'),
                        TextEntry::make('declined_at')
                            ->label('Declined At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('decline_reason')
                            ->label('Decline Reason')
                            ->placeholder('-'),
                        TextEntry::make('issuedBy.name')
                            ->label('Issued By')
                            ->placeholder('-'),
                        TextEntry::make('issued_at')
                            ->label('Issued At')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('items_list')
                            ->label('Requisition Items')
                            ->state(function (Schema $schema): array {
                                $record = $schema->getRecord();

                                return $record->items->map(fn ($item) => sprintf(
                                    '%s — Requested: %d, Approved: %d, Issued: %d',
                                    $item->inventoryItem?->name ?? 'Unknown',
                                    $item->quantity_requested,
                                    $item->quantity_approved ?? 0,
                                    $item->quantity_issued ?? 0,
                                ))->toArray();
                            })
                            ->bulleted(),
                    ]),
            ]);
    }
}
