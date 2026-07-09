<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Classes\Services\InventoryAnalyticsService;
use Modules\Inventory\Data\InventoryReportCriteria;

class InventoryReportCsvController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('view_inventory_report');

        $criteria = InventoryReportCriteria::fromRequest(request()->query());
        $rows = app(InventoryAnalyticsService::class)->toCsvRows($criteria);

        $output = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inventory-report.csv"',
        ]);
    }
}
