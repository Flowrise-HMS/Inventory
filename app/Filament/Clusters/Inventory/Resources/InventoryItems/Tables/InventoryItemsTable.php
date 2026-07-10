<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Classes\Services\BranchService;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('#')->rowIndex(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku'),
                TextColumn::make('category')
                    ->badge()
                    ->color(fn (InventoryItemCategory $state): string => $state->getColor()),
                TextColumn::make('medication_display')
                    ->label('Medication')
                    ->state(fn ($record) => $record->medication?->displayName())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('medication', fn (Builder $medicationQuery) => $medicationQuery
                            ->where('generic_name', 'like', "%{$search}%")
                            ->orWhere('brand_name', 'like', "%{$search}%"));
                    })
                    ->toggleable(),
                TextColumn::make('unit.label')
                    ->label('Unit'),
                TextColumn::make('is_active')
                    ->badge()
                    ->colors([
                        'success' => true,
                        'danger' => false,
                    ])
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(InventoryItemCategory::class),
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                Action::make('add_stock')
                    ->label('Add Stock')
                    ->icon('heroicon-m-plus-circle')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can('create', StockBalance::class) ?? false)
                    ->modalHeading('Add Stock')
                    ->modalDescription(fn (InventoryItem $record) => "Add dispensary stock for {$record->name}")
                    ->schema([
                        Select::make('branch_id')
                            ->label('Branch')
                            ->required()
                            ->searchable()
                            ->options(fn (): array => Branch::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->preload()
                            ->default(fn (): ?string => app(BranchService::class)->getDefaultBranchId()),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('reorder_point')
                            ->label('Reorder point')
                            ->numeric()
                            ->minValue(0)
                            ->nullable(),
                    ])
                    ->action(function (InventoryItem $record, array $data): void {
                        app(StockLedgerService::class)->addOpeningStock(
                            itemId: $record->id,
                            branchId: $data['branch_id'],
                            qty: (int) $data['quantity'],
                            reorderPoint: filled($data['reorder_point'] ?? null) ? (int) $data['reorder_point'] : null,
                        );

                        Notification::make()
                            ->success()
                            ->title('Stock added')
                            ->body("{$data['quantity']} unit(s) added to {$record->name}")
                            ->send();
                    }),
                Action::make('print_stock_card')
                    ->label('Print Stock Card')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.items.stock-card', $record))
                    ->openUrlInNewTab(),
                Action::make('download_stock_card')
                    ->label('Download Stock Card')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('inventory.items.stock-card', ['item' => $record, 'download' => 1]))
                    ->openUrlInNewTab(),
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
