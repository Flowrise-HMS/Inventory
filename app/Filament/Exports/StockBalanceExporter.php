<?php

namespace Modules\Inventory\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Modules\Inventory\Models\StockBalance;

class StockBalanceExporter extends Exporter
{
    protected static ?string $model = StockBalance::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('inventoryItem.name'),
            ExportColumn::make('inventoryItem.sku'),
            ExportColumn::make('branch.name'),
            ExportColumn::make('department.name'),
            ExportColumn::make('location_type'),
            ExportColumn::make('lot_number'),
            ExportColumn::make('expiry_date'),
            ExportColumn::make('quantity_on_hand'),
            ExportColumn::make('reorder_point'),
            ExportColumn::make('unit.name'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your stock balance export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
