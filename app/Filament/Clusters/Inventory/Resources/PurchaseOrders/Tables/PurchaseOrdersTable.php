<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
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
                    ->visible(fn ($record): bool => in_array($record->status, [
                        PurchaseOrderStatus::Submitted,
                        PurchaseOrderStatus::PartiallyReceived,
                    ]))
                    ->fillForm(fn ($record): array => [
                        'items' => $record->items()->with('inventoryItem')->get()->map(fn ($item) => [
                            'purchase_order_item_id' => $item->id,
                            'item_label' => $item->inventoryItem?->name ?? __('Unknown item'),
                            'quantity_received' => $item->quantity_ordered - $item->quantity_received,
                            'remaining' => $item->quantity_ordered - $item->quantity_received,
                        ])->all(),
                    ])
                    ->form([
                        Repeater::make('items')
                            ->label('Line items')
                            ->schema([
                                TextInput::make('purchase_order_item_id')->hidden(),
                                TextInput::make('item_label')
                                    ->label('Item')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('remaining')
                                    ->label('Remaining')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric(),
                                TextInput::make('quantity_received')
                                    ->label('Quantity to receive')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('lot_number')
                                    ->label(__('Lot / batch #'))
                                    ->maxLength(100),
                                DatePicker::make('expiry_date')
                                    ->label(__('Expiry date'))
                                    ->native(false),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->action(function ($record, array $data): void {
                        $items = collect($data['items'])
                            ->filter(fn (array $item): bool => (int) $item['quantity_received'] > 0)
                            ->map(fn (array $item): array => [
                                'purchase_order_item_id' => $item['purchase_order_item_id'],
                                'quantity_received' => (int) $item['quantity_received'],
                                'lot_number' => filled($item['lot_number'] ?? null) ? (string) $item['lot_number'] : null,
                                'expiry_date' => filled($item['expiry_date'] ?? null) ? (string) $item['expiry_date'] : null,
                            ])
                            ->values()
                            ->all();

                        if ($items === []) {
                            Notification::make()->warning()->title('No quantities to receive')->send();

                            return;
                        }

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
