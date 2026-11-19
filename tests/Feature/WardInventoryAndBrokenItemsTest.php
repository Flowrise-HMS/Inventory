<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\BedStatus;
use Modules\Core\Enums\LocationType;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Classes\Services\WardItemIssueService;
use Modules\Inventory\Classes\Support\InventoryWardInventoryOverview;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\WardItemIssueCategory;
use Modules\Inventory\Enums\WardItemIssueSeverity;
use Modules\Inventory\Enums\WardItemIssueStatus;
use Modules\Inventory\Enums\WardItemIssueType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Tests\TestCase;

class WardInventoryAndBrokenItemsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Department $department;

    protected Location $wardA;

    protected Location $wardB;

    protected Location $bedA1;

    protected User $incharge;

    protected User $regularNurse;

    protected InventoryItem $gloves;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->department = Department::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->incharge = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->regularNurse = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

        $this->wardA = Location::factory()->create([
            'branch_id' => $this->branch->id,
            'type' => LocationType::ROOM,
            'name' => 'Male Medical Ward',
            'is_active' => true,
            'nurse_in_charge_id' => $this->incharge->id,
        ]);

        $this->wardB = Location::factory()->create([
            'branch_id' => $this->branch->id,
            'type' => LocationType::ROOM,
            'name' => 'Female Medical Ward',
            'is_active' => true,
        ]);

        $this->bedA1 = Location::factory()->create([
            'branch_id' => $this->branch->id,
            'parent_id' => $this->wardA->id,
            'type' => LocationType::BED,
            'name' => 'Bed A1',
            'is_active' => true,
            'status' => BedStatus::AVAILABLE,
        ]);

        $this->gloves = InventoryItem::factory()->create(['name' => 'Examination Gloves', 'is_active' => true]);

        app(StockLedgerService::class)->addOpeningStock($this->gloves->id, $this->branch->id, 100);
    }

    public function test_per_ward_requisition_endorsement_and_ward_stock_isolation(): void
    {
        config()->set('inventory.require_ward_incharge_endorsement', true);

        $service = app(RequisitionService::class);

        // Regular nurse creates requisition for Ward A -> starts at PendingIncharge
        $nurseReq = $service->create([
            'branch_id' => $this->branch->id,
            'department_id' => $this->department->id,
            'location_id' => $this->wardA->id,
            'requestor_id' => $this->regularNurse->id,
            'items' => [
                ['inventory_item_id' => $this->gloves->id, 'quantity_requested' => 20],
            ],
        ]);

        $this->assertSame(RequisitionStatus::PendingIncharge, $nurseReq->status);

        // Ward Incharge endorses -> transitions to Pending
        $service->endorse($nurseReq, $this->incharge);
        $this->assertSame(RequisitionStatus::Pending, $nurseReq->fresh()->status);
        $this->assertSame($this->incharge->id, $nurseReq->fresh()->endorsed_by);

        // Approve and issue to Ward A
        $this->actingAs($this->incharge);
        $service->approve($nurseReq->fresh('items'));
        $service->issue($nurseReq->items()->first(), 20);

        $wardABalance = StockBalance::query()
            ->where('inventory_item_id', $this->gloves->id)
            ->where('location_type', StockLocationType::Ward->value)
            ->where('location_id', $this->wardA->id)
            ->value('quantity_on_hand');

        $wardBBalance = StockBalance::query()
            ->where('inventory_item_id', $this->gloves->id)
            ->where('location_type', StockLocationType::Ward->value)
            ->where('location_id', $this->wardB->id)
            ->value('quantity_on_hand');

        $this->assertSame(20, $wardABalance);
        $this->assertNull($wardBBalance);
    }

    public function test_broken_bed_report_blocks_bed_and_unblocks_on_resolution(): void
    {
        $issueService = app(WardItemIssueService::class);

        $issue = $issueService->report($this->wardA, [
            'item_name' => 'Bed A1 Side Rail',
            'category' => WardItemIssueCategory::BED_FURNITURE,
            'issue_type' => WardItemIssueType::BROKEN,
            'severity' => WardItemIssueSeverity::HIGH,
            'bed_id' => $this->bedA1->id,
            'block_bed' => true,
            'description' => 'Side rail hinge snapped; unsafe for patients.',
        ], $this->incharge);

        $this->assertSame(WardItemIssueStatus::INCHARGE_VERIFIED, $issue->status);
        $this->assertTrue($issue->bed_blocked);
        $this->assertSame(BedStatus::BLOCKED, $this->bedA1->fresh()->bedStatus());

        $summary = app(InventoryWardInventoryOverview::class)->summaryForWard($this->wardA->id, $this->branch->id);
        $this->assertSame(1, $summary['open_issues_count']);
        $this->assertSame(1, $summary['critical_issues_count']);

        $resolved = $issueService->resolve(
            issue: $issue,
            resolutionNotes: 'Hinge welded and tested.',
            condemned: false,
            unblockBed: true,
            resolver: $this->incharge,
        );

        $this->assertSame(WardItemIssueStatus::RESOLVED, $resolved->status);
        $this->assertSame(BedStatus::CLEANING, $this->bedA1->fresh()->bedStatus());
    }
}
