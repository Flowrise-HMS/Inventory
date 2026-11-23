<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\StockBalanceResource;
use Modules\Inventory\Models\StockBalance;

class ExpiringStockTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardStores;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $levels = app(StoreStockLevelService::class);

        return $table
            ->heading(__('Expired and expiring lots'))
            ->description(__('Lots still on the shelf that expire within :days days. Expired stock is never issued; write it off from the stock balance.', [
                'days' => $levels->expiryWarningDays(),
            ]))
            ->query(fn () => $levels->expiringQuery($this->dashboardStoreIds()))
            ->columns([
                TextColumn::make('inventoryItem.name')
                    ->label(__('Item'))
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('store.name')
                    ->label(__('Store')),
                TextColumn::make('lot_number')
                    ->label(__('Lot'))
                    ->placeholder('—'),
                TextColumn::make('expiry_date')
                    ->label(__('Expiry'))
                    ->date()
                    ->badge()
                    ->color(fn (StockBalance $record): string => $record->expiry_date->lt(now()->startOfDay()) ? 'danger' : 'warning')
                    ->description(fn (StockBalance $record): string => $record->expiry_date->lt(now()->startOfDay())
                        ? __('Expired')
                        : trans_choice('in :count day|in :count days', (int) now()->startOfDay()->diffInDays($record->expiry_date))),
                TextColumn::make('quantity_on_hand')
                    ->label(__('Qty'))
                    ->numeric()
                    ->suffix(fn (StockBalance $record): string => $record->unit?->label ? ' '.$record->unit->label : ''),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (StockBalance $record): string => StockBalanceResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('Nothing expiring soon'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
