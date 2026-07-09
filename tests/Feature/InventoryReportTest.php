<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\InventoryAnalyticsService;
use Modules\Inventory\Data\InventoryReportCriteria;
use Modules\Inventory\Models\InventoryItem;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_analytics_service_builds_report_structure(): void
    {
        $criteria = InventoryReportCriteria::fromRequest(['preset' => 'today']);

        $report = app(InventoryAnalyticsService::class)->buildFromCriteria($criteria);

        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('items_by_category', $report);
        $this->assertArrayHasKey('transaction_trend', $report);
        $this->assertArrayHasKey('po_status', $report);
        $this->assertArrayHasKey('requisitions_by_dept', $report);
        $this->assertArrayHasKey('stock_by_location', $report);
        $this->assertArrayHasKey('low_stock_items', $report);
        $this->assertArrayHasKey('recent_transactions', $report);
        $this->assertArrayHasKey('recent_pos', $report);

        $this->assertArrayHasKey('active_items', $report['summary']);
        $this->assertArrayHasKey('low_stock_count', $report['summary']);
        $this->assertArrayHasKey('po_count', $report['summary']);
        $this->assertArrayHasKey('requisition_count', $report['summary']);
        $this->assertArrayHasKey('transfer_count', $report['summary']);
        $this->assertArrayHasKey('total_spend', $report['summary']);
    }

    public function test_analytics_service_with_data(): void
    {
        Permission::firstOrCreate(['name' => 'view_inventory_report', 'guard_name' => 'web']);

        $branch = Branch::factory()->create();
        InventoryItem::factory()->count(3)->create(['is_active' => true]);

        $criteria = InventoryReportCriteria::fromRequest([
            'preset' => 'today',
            'branch_id' => $branch->id,
        ]);

        $report = app(InventoryAnalyticsService::class)->buildFromCriteria($criteria);

        $this->assertGreaterThanOrEqual(3, $report['summary']['active_items']);
        $this->assertArrayHasKey('labels', $report['items_by_category']);
    }

    public function test_csv_export_returns_csv_file(): void
    {
        Permission::firstOrCreate(['name' => 'view_inventory_report', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('view_inventory_report');
        $this->actingAs($user);

        $response = $this->get(route('inventory.reports.csv', ['preset' => 'today']));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $content = (string) $response->getContent();
        $this->assertStringContainsString('Section,Key,Value', $content);
        $this->assertStringContainsString('Active items', $content);
        $this->assertStringContainsString('Total spend', $content);
        $this->assertStringNotContainsString('active_items', $content);
    }

    public function test_csv_export_returns_403_without_permission(): void
    {
        Permission::firstOrCreate(['name' => 'view_inventory_report', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('inventory.reports.csv', ['preset' => 'today']));

        $response->assertForbidden();
    }

    public function test_report_page_loads(): void
    {
        $this->markTestSkipped('Requires full Filament rendering environment.');
    }
}
