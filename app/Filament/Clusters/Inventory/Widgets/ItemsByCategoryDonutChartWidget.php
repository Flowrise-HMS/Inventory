<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets;

use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Reactive;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;

class ItemsByCategoryDonutChartWidget extends ChartWidget
{
    use InteractsWithWidgetShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Items by category';

    protected int|string|array $columnSpan = 1;

    #[Reactive]
    public ?array $reportPayload = null;

    protected function getData(): array
    {
        $data = $this->reportPayload['items_by_category'] ?? ['labels' => [], 'data' => []];

        if ($data['labels'] === []) {
            return ['labels' => [], 'datasets' => []];
        }

        return [
            'labels' => $data['labels'],
            'datasets' => [[
                'data' => array_map(fn ($v) => (float) $v, $data['data']),
                'backgroundColor' => ['#16a34a', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6'],
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return ['responsive' => true, 'maintainAspectRatio' => false];
    }
}
