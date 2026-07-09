<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Concerns\InteractsWithReportPayload;

class RecentTransactionsTableWidget extends BaseWidget
{
    use InteractsWithReportPayload;
    use InteractsWithWidgetShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent transactions'))
            ->records(fn (): array => $this->reportRows('recent_transactions'))
            ->columns([
                TextColumn::make('date')->label(__('Date')),
                TextColumn::make('item')->label(__('Item')),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('delta')->label(__('Qty'))->numeric(),
                TextColumn::make('balance')->label(__('Balance'))->numeric(),
                TextColumn::make('branch')->label(__('Branch')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No recent transactions'));
    }
}
