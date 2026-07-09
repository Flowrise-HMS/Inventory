<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Concerns\InteractsWithReportPayload;

class LowStockItemsTableWidget extends BaseWidget
{
    use InteractsWithReportPayload;
    use InteractsWithWidgetShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Low stock items'))
            ->records(fn (): array => $this->reportRows('low_stock_items'))
            ->columns([
                TextColumn::make('item')->label(__('Item')),
                TextColumn::make('branch')->label(__('Branch')),
                TextColumn::make('location')->label(__('Location')),
                TextColumn::make('quantity_on_hand')->label(__('On hand'))->numeric(),
                TextColumn::make('reorder_point')->label(__('Reorder point'))->numeric(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No low stock items'));
    }
}
