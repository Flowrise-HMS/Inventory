<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Concerns\InteractsWithReportPayload;

class RecentPurchaseOrdersTableWidget extends BaseWidget
{
    use InteractsWithReportPayload;
    use InteractsWithWidgetShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent purchase orders'))
            ->records(fn (): array => $this->reportRows('recent_pos'))
            ->columns([
                TextColumn::make('po_number')->label(__('PO #')),
                TextColumn::make('supplier')->label(__('Supplier')),
                TextColumn::make('branch')->label(__('Branch')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('ordered_at')->label(__('Ordered')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No recent purchase orders'));
    }
}
