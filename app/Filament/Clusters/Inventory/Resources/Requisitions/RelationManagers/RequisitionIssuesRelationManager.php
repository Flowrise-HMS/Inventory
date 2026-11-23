<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Models\RequisitionIssue;

/**
 * Hand-over log: what the store issued, why it was short, who collected and
 * signed, and whether the ward confirmed or disputed it.
 */
class RequisitionIssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'issues';

    protected static ?string $title = 'Hand-overs';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['inventoryItem', 'issuedBy', 'collectedBy', 'receiptConfirmedBy']))
            ->defaultSort('issued_at', 'desc')
            ->emptyStateHeading(__('Nothing issued yet'))
            ->columns([
                TextColumn::make('issued_at')
                    ->label(__('Issued'))
                    ->dateTime()
                    ->sortable()
                    ->description(fn (RequisitionIssue $record): ?string => $record->issuedBy?->name),
                TextColumn::make('inventoryItem.name')
                    ->label(__('Item'))
                    ->wrap(),
                TextColumn::make('quantity_issued')
                    ->label(__('Qty issued'))
                    ->description(fn (RequisitionIssue $record): ?string => $record->quantity_issued < $record->quantity_outstanding_before
                        ? __('of :n owed', ['n' => $record->quantity_outstanding_before])
                        : null),
                TextColumn::make('short_issue_reason')
                    ->label(__('Short because'))
                    ->placeholder('-')
                    ->description(fn (RequisitionIssue $record): ?string => $record->short_issue_note)
                    ->wrap(),
                TextColumn::make('remainder_action')
                    ->label(__('Rest'))
                    ->badge()
                    ->formatStateUsing(fn ($state, RequisitionIssue $record): string => $record->quantity_closed > 0
                        ? __('Closed (:n)', ['n' => $record->quantity_closed])
                        : __('Backorder'))
                    ->color(fn (RequisitionIssue $record): string => $record->quantity_closed > 0 ? 'gray' : 'warning')
                    ->placeholder('-'),
                TextColumn::make('collector')
                    ->label(__('Collected by'))
                    ->state(fn (RequisitionIssue $record): ?string => $record->collectorName())
                    ->placeholder('-'),
                ImageColumn::make('signature')
                    ->label(__('Signature'))
                    ->state(fn (RequisitionIssue $record): ?string => $record->hasSignature()
                        ? route('inventory.requisition-issues.signature', $record)
                        : null)
                    ->imageHeight(36)
                    ->extraImgAttributes(['style' => 'background:#fff; border-radius:4px;', 'alt' => __('Collector signature')]),
                TextColumn::make('receipt_status')
                    ->label(__('Ward receipt'))
                    ->badge()
                    ->description(fn (RequisitionIssue $record): ?string => match (true) {
                        $record->receipt_status === ReceiptStatus::Disputed => __('Received :r of :i: :note', [
                            'r' => $record->quantity_received,
                            'i' => $record->quantity_issued,
                            'note' => $record->discrepancy_note,
                        ]),
                        $record->receipt_confirmed_at !== null => trim(($record->receiptConfirmedBy?->name ?? '').' · '.$record->receipt_confirmed_at->format('Y-m-d H:i'), ' ·'),
                        default => null,
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('receipt_status')
                    ->label(__('Ward receipt'))
                    ->options(ReceiptStatus::class),
            ]);
    }
}
