<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Inventory\Classes\Services\InterBranchTransferService;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Models\StockTransfer;

class StockTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('transfer_number')
                    ->searchable(),
                TextColumn::make('fromBranch.name')
                    ->sortable(),
                TextColumn::make('toBranch.name')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (StockTransferStatus $state): ?string => $state->getColor()),
                TextColumn::make('shipped_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')
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
                    ->options(StockTransferStatus::class),
            ])
            ->recordActions([
                Action::make('ship')
                    ->label('Ship')
                    ->color('info')
                    ->visible(fn ($record) => $record->status === StockTransferStatus::Draft)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(InterBranchTransferService::class)->ship($record, []);
                        Notification::make()
                            ->success()
                            ->title('Transfer shipped successfully')
                            ->send();
                    }),
                Action::make('receive')
                    ->label('Receive')
                    ->color('success')
                    ->visible(fn ($record): bool => in_array($record->status, [
                        StockTransferStatus::Shipped,
                        StockTransferStatus::PartiallyReceived,
                    ], true))
                    ->fillForm(fn (StockTransfer $record): array => [
                        'items' => $record->items()->with('inventoryItem')->get()->map(fn ($item) => [
                            'stock_transfer_item_id' => $item->id,
                            'item_label' => $item->inventoryItem?->name ?? __('Unknown item'),
                            'quantity_received' => ($item->quantity_shipped ?? 0) - $item->quantity_received,
                            'remaining' => ($item->quantity_shipped ?? 0) - $item->quantity_received,
                        ])->all(),
                    ])
                    ->form([
                        Repeater::make('items')
                            ->label('Line items')
                            ->schema([
                                TextInput::make('stock_transfer_item_id')->hidden(),
                                TextInput::make('item_label')
                                    ->label('Item')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('remaining')
                                    ->label('In transit')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric(),
                                TextInput::make('quantity_received')
                                    ->label('Quantity to receive')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->action(function (StockTransfer $record, array $data): void {
                        $quantities = collect($data['items'])
                            ->filter(fn (array $item): bool => (int) $item['quantity_received'] > 0)
                            ->mapWithKeys(fn (array $item): array => [
                                $item['stock_transfer_item_id'] => (int) $item['quantity_received'],
                            ])
                            ->all();

                        if ($quantities === []) {
                            Notification::make()->warning()->title('No quantities to receive')->send();

                            return;
                        }

                        app(InterBranchTransferService::class)->receive($record, $quantities);
                        Notification::make()
                            ->success()
                            ->title('Transfer received successfully')
                            ->send();
                    }),
                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-m-lock-closed')
                    ->color('gray')
                    ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::PartiallyReceived
                        && $record->items()->whereColumn('quantity_received', '<', 'quantity_shipped')->exists())
                    ->form([
                        Textarea::make('closed_reason')->label('Reason')->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (StockTransfer $record, array $data): void {
                        app(InterBranchTransferService::class)->close($record, $data['closed_reason']);
                        Notification::make()->success()->title('Transfer closed')->send();
                    }),
                Action::make('print_note')
                    ->label('Print Note')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.stock-transfers.note', $record))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => in_array($record->status, [
                        StockTransferStatus::Shipped,
                        StockTransferStatus::PartiallyReceived,
                        StockTransferStatus::Received,
                    ])),
                Action::make('download_note')
                    ->label('Download Note')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.stock-transfers.note', ['transfer' => $record, 'download' => 1]))
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => in_array($record->status, [
                        StockTransferStatus::Shipped,
                        StockTransferStatus::PartiallyReceived,
                        StockTransferStatus::Received,
                    ])),
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
