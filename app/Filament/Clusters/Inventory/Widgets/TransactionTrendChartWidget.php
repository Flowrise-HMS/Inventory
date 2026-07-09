<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets;

use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Reactive;
use Modules\Core\Filament\Concerns\InteractsWithWidgetShield;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;

class TransactionTrendChartWidget extends ChartWidget
{
    use InteractsWithWidgetShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Daily transaction volume (in vs out)';

    protected int|string|array $columnSpan = 2;

    #[Reactive]
    public ?array $reportPayload = null;

    protected function getData(): array
    {
        $trend = $this->reportPayload['transaction_trend'] ?? [
            'labels' => [],
            'in' => [],
            'out' => [],
        ];

        if ($trend['labels'] === []) {
            return ['labels' => [], 'datasets' => []];
        }

        return [
            'labels' => $trend['labels'],
            'datasets' => [
                [
                    'label' => __('Stock in'),
                    'data' => array_map(fn ($v) => (float) $v, $trend['in']),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.1)',
                    'tension' => 0.3,
                    'fill' => false,
                ],
                [
                    'label' => __('Stock out'),
                    'data' => array_map(fn ($v) => (float) $v, $trend['out']),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'tension' => 0.3,
                    'fill' => false,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
