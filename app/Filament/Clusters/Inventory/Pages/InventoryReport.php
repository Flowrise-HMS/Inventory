<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\WidgetConfiguration;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\InventoryAnalyticsService;
use Modules\Inventory\Data\InventoryReportCriteria;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\ItemsByCategoryDonutChartWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\LowStockItemsTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\PurchaseOrderStatusDonutChartWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\RecentPurchaseOrdersTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\RecentTransactionsTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\RequisitionsByDepartmentBarChartWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\StockByLocationDonutChartWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\TransactionTrendChartWidget;

class InventoryReport extends Page
{
    use HasPageShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static ?string $navigationLabel = 'Inventory report';

    protected static ?string $title = 'Inventory report';

    protected string $view = 'inventory::filament.clusters.inventory.pages.inventory-report';

    public ?string $preset = 'today';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?string $branchId = null;

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(): void
    {
        $this->branchId = request()->query('branch_id');

        $hasCustomDates = request()->has('start_date') && request()->has('end_date');
        $this->preset = $hasCustomDates ? 'custom' : (string) request()->query('preset', 'today');

        $criteria = InventoryReportCriteria::fromRequest(request()->query());

        $this->startDate = $criteria->startDate->toDateString();
        $this->endDate = $criteria->endDate->toDateString();

        $this->loadReport();
    }

    /**
     * @return array<string, string>
     */
    public function getBranchOptionsProperty(): array
    {
        return Branch::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function presetUrl(string $preset): string
    {
        return static::getUrl([
            'preset' => $preset,
            'branch_id' => $this->branchId,
        ]);
    }

    /**
     * @return array<class-string|int, class-string|WidgetConfiguration>
     */
    protected function getFooterWidgets(): array
    {
        $payload = ['reportPayload' => $this->report];

        return [
            ItemsByCategoryDonutChartWidget::make($payload),
            PurchaseOrderStatusDonutChartWidget::make($payload),
            RequisitionsByDepartmentBarChartWidget::make($payload),
            StockByLocationDonutChartWidget::make($payload),
            TransactionTrendChartWidget::make($payload),
        ];
    }

    /**
     * @return array<class-string|int, class-string|WidgetConfiguration>
     */
    public function getReportTableWidgets(): array
    {
        $payload = ['reportPayload' => $this->report];

        return [
            LowStockItemsTableWidget::make($payload),
            RecentTransactionsTableWidget::make($payload),
            RecentPurchaseOrdersTableWidget::make($payload),
        ];
    }

    protected function loadReport(): void
    {
        $this->report = app(InventoryAnalyticsService::class)->buildFromCriteria($this->buildCriteria());
    }

    protected function buildCriteria(): InventoryReportCriteria
    {
        return InventoryReportCriteria::fromRequest([
            'preset' => $this->preset,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'branch_id' => $this->branchId,
        ]);
    }
}
