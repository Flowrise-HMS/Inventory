<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Classes\Services\PurchaseOrderService;
use Modules\Inventory\Enums\PurchaseOrderStatus;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('po_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PurchaseOrderStatus $state): string => $state->getColor()),
                TextColumn::make('ordered_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expected_delivery_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PurchaseOrderStatus::class),
            ])
            ->recordActions([
                Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-m-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->status === PurchaseOrderStatus::Draft)
                    ->action(function ($record): void {
                        app(PurchaseOrderService::class)->submit($record);
                        Notification::make()
                            ->success()
                            ->title('Purchase Order submitted successfully')
                            ->send();
                    }),
                Action::make('receive')
                    ->label('Receive')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => in_array($record->status, [
                        PurchaseOrderStatus::Submitted,
                        PurchaseOrderStatus::PartiallyReceived,
                    ]))
                    ->action(function ($record): void {
                        $items = $record->items()->get()->map(fn ($item) => [
                            'purchase_order_item_id' => $item->id,
                            'quantity_received' => $item->quantity_ordered - $item->quantity_received,
                        ])->all();

                        app(PurchaseOrderService::class)->receive($record, [
                            'received_at' => now(),
                            'items' => $items,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Purchase Order received successfully')
                            ->send();
                    }),
                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-m-lock-closed')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->status === PurchaseOrderStatus::Received)
                    ->action(function ($record): void {
                        app(PurchaseOrderService::class)->closeRemaining($record);
                        Notification::make()
                            ->success()
                            ->title('Purchase Order closed successfully')
                            ->send();
                    }),
                Action::make('print_grn')
                    ->label('Print GRN')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.purchase-orders.grn', $record))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => $record->receipts()->exists()),
                Action::make('download_grn')
                    ->label('Download GRN')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.purchase-orders.grn', ['purchaseOrder' => $record, 'download' => 1]))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => $record->receipts()->exists()),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
