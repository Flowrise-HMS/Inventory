<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets;

use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Reactive;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;

class RequisitionsByDepartmentBarChartWidget extends ChartWidget
{
    use InteractsWithWidgetShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Requisitions by department';

    protected int|string|array $columnSpan = 1;

    #[Reactive]
    public ?array $reportPayload = null;

    protected function getData(): array
    {
        $data = $this->reportPayload['requisitions_by_dept'] ?? ['labels' => [], 'data' => []];

        if ($data['labels'] === []) {
            return ['labels' => [], 'datasets' => []];
        }

        return [
            'labels' => $data['labels'],
            'datasets' => [[
                'label' => __('Requisitions'),
                'data' => array_map(fn ($v) => (float) $v, $data['data']),
                'backgroundColor' => '#3b82f6',
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'indexAxis' => 'y',
            'scales' => ['x' => ['beginAtZero' => true]],
        ];
    }
}
