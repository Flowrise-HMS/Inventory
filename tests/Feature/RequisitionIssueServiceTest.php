<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Enums\LocationType;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\Pdf\RequisitionVoucherPdfService;
use Modules\Inventory\Classes\Services\RequisitionIssueService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RemainderAction;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Enums\ShortIssueReason;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionIssue;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RequisitionIssueServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Department $department;

    private Location $ward;

    private User $requestor;

    private User $incharge;

    private User $storekeeper;

    private User $porterAccount;

    private InventoryItem $gloves;

    private InventoryItem $soap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        Storage::fake('local');

        $this->branch = Branch::factory()->create();
        $this->department = Department::factory()->create();
        $this->incharge = User::factory()->create(['is_active' => true]);
        $this->ward = Location::factory()->create([
            'branch_id' => $this->branch->id,
            'type' => LocationType::ROOM,
            'name' => 'Emergency Ward',
            'is_active' => true,
            'nurse_in_charge_id' => $this->incharge->id,
        ]);
        $this->department->locations()->attach($this->ward->id, ['is_primary' => true]);

        $this->requestor = User::factory()->create();
        $this->storekeeper = User::factory()->create();
        $this->porterAccount = User::factory()->create();

        $this->gloves = InventoryItem::factory()->create(['name' => 'Examination Gloves']);
        $this->soap = InventoryItem::factory()->create(['name' => 'Liquid Soap']);

        $ledger = app(StockLedgerService::class);
        $ledger->addOpeningStock($this->gloves->id, $this->branch->id, 1);
        $ledger->addOpeningStock($this->soap->id, $this->branch->id, 10);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(40, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 2, 10, 38, 12, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * Emergency ward asks for 3 packs of gloves and 2 bottles of soap.
     */
    private function approvedRequisition(): Requisition
    {
        $this->actingAs($this->requestor);

        $service = app(RequisitionService::class);
        $requisition = $service->create([
            'branch_id' => $this->branch->id,
            'department_id' => $this->department->id,
            'location_id' => $this->ward->id,
            'items' => [
                ['inventory_item_id' => $this->gloves->id, 'quantity_requested' => 3],
                ['inventory_item_id' => $this->soap->id, 'quantity_requested' => 2],
            ],
        ]);
        $service->approve($requisition);

        $this->actingAs($this->storekeeper);

        return $requisition->fresh('items');
    }

    private function line(Requisition $requisition, InventoryItem $item): string
    {
        return (string) $requisition->items->firstWhere('inventory_item_id', $item->id)->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function collector(): array
    {
        return ['user_id' => $this->porterAccount->id, 'signature' => $this->signature()];
    }

    public function test_short_issue_backorders_the_rest_and_records_signed_handover(): void
    {
        $requisition = $this->approvedRequisition();

        $issues = app(RequisitionIssueService::class)->issueHandover($requisition, [
            [
                'requisition_item_id' => $this->line($requisition, $this->gloves),
                'quantity' => 1,
                'short_issue_reason' => ShortIssueReason::OutOfStock->value,
                'remainder_action' => RemainderAction::Backorder->value,
            ],
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector());

        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues->pluck('handover_reference')->unique()->count(), 'One hand-over, one reference.');

        $gloveIssue = $issues->firstWhere('inventory_item_id', $this->gloves->id);
        $this->assertSame(1, $gloveIssue->quantity_issued);
        $this->assertSame(3, $gloveIssue->quantity_outstanding_before);
        $this->assertSame(ShortIssueReason::OutOfStock, $gloveIssue->short_issue_reason);
        $this->assertSame(RemainderAction::Backorder, $gloveIssue->remainder_action);
        $this->assertSame($this->porterAccount->id, $gloveIssue->collected_by);
        $this->assertSame($this->storekeeper->id, $gloveIssue->issued_by);
        $this->assertSame(ReceiptStatus::AwaitingConfirmation, $gloveIssue->receipt_status);
        Storage::disk('local')->assertExists($gloveIssue->collector_signature_path);

        $requisition->refresh();
        $this->assertSame(RequisitionStatus::PartiallyIssued, $requisition->status);
        $this->assertSame(2, $requisition->items->firstWhere('inventory_item_id', $this->gloves->id)->outstandingQuantity());
        $this->assertSame(0, StockBalance::dispensaryOnHand($this->gloves->id, $this->branch->id));
        $this->assertSame(8, StockBalance::dispensaryOnHand($this->soap->id, $this->branch->id));
    }

    public function test_backordered_rest_can_be_issued_later_and_completes_the_requisition(): void
    {
        $requisition = $this->approvedRequisition();
        $service = app(RequisitionIssueService::class);
        $gloves = $this->line($requisition, $this->gloves);

        $service->issueHandover($requisition, [
            ['requisition_item_id' => $gloves, 'quantity' => 1, 'short_issue_reason' => 'out_of_stock', 'remainder_action' => 'backorder'],
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector());

        app(StockLedgerService::class)->addOpeningStock($this->gloves->id, $this->branch->id, 5);

        $service->issueHandover($requisition->fresh(), [
            ['requisition_item_id' => $gloves, 'quantity' => 2],
        ], ['name' => 'Kofi (porter)', 'signature' => $this->signature()]);

        $requisition->refresh();
        $this->assertSame(RequisitionStatus::Issued, $requisition->status);
        $this->assertSame(2, $requisition->issues()->distinct()->count('handover_reference'));
        $this->assertSame('Kofi (porter)', $requisition->issues()->latest('issued_at')->latest('id')->whereNotNull('collected_by_name')->value('collected_by_name'));
    }

    public function test_closing_the_rest_settles_the_line(): void
    {
        $requisition = $this->approvedRequisition();

        app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->gloves), 'quantity' => 1, 'short_issue_reason' => 'rationed', 'remainder_action' => 'close'],
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector());

        $requisition->refresh();
        $glovesLine = $requisition->items->firstWhere('inventory_item_id', $this->gloves->id);

        $this->assertSame(RequisitionStatus::Issued, $requisition->status);
        $this->assertSame(2, $glovesLine->quantity_closed);
        $this->assertSame(0, $glovesLine->outstandingQuantity());
    }

    public function test_closing_everything_without_issuing_closes_the_requisition_without_a_signature(): void
    {
        $requisition = $this->approvedRequisition();

        app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->gloves), 'quantity' => 0, 'short_issue_reason' => 'out_of_stock', 'remainder_action' => 'close'],
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 0, 'short_issue_reason' => 'other', 'short_issue_note' => 'Discontinued', 'remainder_action' => 'close'],
        ], ['user_id' => null, 'name' => null, 'signature' => null]);

        $this->assertSame(RequisitionStatus::Closed, $requisition->fresh()->status);
        $this->assertSame(0, RequisitionIssue::query()->where('requisition_id', $requisition->id)->whereNotNull('collector_signature_path')->count());
    }

    public function test_short_issue_needs_a_reason_and_a_choice_for_the_rest(): void
    {
        $requisition = $this->approvedRequisition();
        $service = app(RequisitionIssueService::class);
        $gloves = $this->line($requisition, $this->gloves);

        foreach ([
            [['quantity' => 1, 'remainder_action' => 'backorder'], 'Give a reason'],
            [['quantity' => 1, 'short_issue_reason' => 'out_of_stock'], 'backorder or close'],
            [['quantity' => 1, 'short_issue_reason' => 'other', 'remainder_action' => 'close'], 'Describe the reason'],
        ] as [$line, $message]) {
            try {
                $service->issueHandover($requisition, [['requisition_item_id' => $gloves] + $line], $this->collector());
                $this->fail("Expected failure: {$message}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, RequisitionIssue::query()->count());
        $this->assertSame(1, StockBalance::dispensaryOnHand($this->gloves->id, $this->branch->id));
    }

    public function test_collector_must_be_named_and_must_sign(): void
    {
        $requisition = $this->approvedRequisition();
        $service = app(RequisitionIssueService::class);
        $lines = [['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2]];

        try {
            $service->issueHandover($requisition, $lines, ['signature' => $this->signature()]);
            $this->fail('A collector is required.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('who is collecting', $e->getMessage());
        }

        try {
            $service->issueHandover($requisition, $lines, ['user_id' => $this->porterAccount->id]);
            $this->fail('A signature is required.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must sign', $e->getMessage());
        }

        try {
            $service->issueHandover($requisition, $lines, ['user_id' => $this->porterAccount->id, 'signature' => 'data:image/png;base64,'.base64_encode('not a png')]);
            $this->fail('An invalid signature is rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not valid', $e->getMessage());
        }

        $this->assertSame(10, StockBalance::dispensaryOnHand($this->soap->id, $this->branch->id));
    }

    public function test_failed_handover_leaves_no_stock_movement_or_orphan_signature(): void
    {
        $requisition = $this->approvedRequisition();

        // Stock drained between the form opening and submitting.
        app(StockLedgerService::class)->lockAndDecrement(
            itemId: $this->soap->id,
            branchId: $this->branch->id,
            locationType: \Modules\Inventory\Enums\StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 9,
            transactionType: \Modules\Inventory\Enums\TransactionType::Adjust,
        );

        try {
            app(RequisitionIssueService::class)->issueHandover($requisition, [
                ['requisition_item_id' => $this->line($requisition, $this->gloves), 'quantity' => 1, 'short_issue_reason' => 'out_of_stock', 'remainder_action' => 'backorder'],
                ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
            ], $this->collector());
            $this->fail('Expected insufficient stock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertSame(1, StockBalance::dispensaryOnHand($this->gloves->id, $this->branch->id), 'The gloves line must roll back too.');
        $this->assertSame(0, RequisitionIssue::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('inventory/signatures'));
        $this->assertSame(RequisitionStatus::Approved, $requisition->fresh()->status);
    }

    public function test_requestor_confirms_full_receipt(): void
    {
        $requisition = $this->approvedRequisition();
        $issues = app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector());

        $issue = $issues->first();
        app(RequisitionIssueService::class)->confirmReceipt($requisition, [
            $issue->id => ['quantity_received' => 2],
        ], $this->requestor);

        $issue->refresh();
        $this->assertSame(ReceiptStatus::Confirmed, $issue->receipt_status);
        $this->assertSame(2, $issue->quantity_received);
        $this->assertSame($this->requestor->id, $issue->receipt_confirmed_by);
    }

    public function test_ward_incharge_disputes_a_shortfall_and_store_keepers_are_notified(): void
    {
        $requisition = $this->approvedRequisition();
        $store = Store::query()->findOrFail($requisition->store_id);
        $store->keepers()->attach($this->storekeeper->id);

        $issue = app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector())->first();

        try {
            app(RequisitionIssueService::class)->confirmReceipt($requisition, [$issue->id => ['quantity_received' => 1]], $this->incharge);
            $this->fail('A note is required when less was received.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Explain the difference', $e->getMessage());
        }

        app(RequisitionIssueService::class)->confirmReceipt($requisition, [
            $issue->id => ['quantity_received' => 1, 'discrepancy_note' => 'One bottle was broken.'],
        ], $this->incharge);

        $issue->refresh();
        $this->assertSame(ReceiptStatus::Disputed, $issue->receipt_status);
        $this->assertSame(1, $issue->quantity_received);
        $this->assertSame('One bottle was broken.', $issue->discrepancy_note);
        $this->assertSame(1, $this->storekeeper->notifications()->count());
    }

    public function test_storekeeper_and_unrelated_users_cannot_confirm_receipt(): void
    {
        $requisition = $this->approvedRequisition();
        $issue = app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector())->first();

        $service = app(RequisitionIssueService::class);

        $this->assertFalse($service->canConfirmReceipt($this->storekeeper, $issue));
        $this->assertFalse($service->canConfirmReceipt(User::factory()->create(), $issue));
        $this->assertTrue($service->canConfirmReceipt($this->porterAccount, $issue), 'The named collector may confirm.');
        $this->assertTrue($service->canConfirmReceipt($this->incharge, $issue));

        $this->expectException(\RuntimeException::class);
        $service->confirmReceipt($requisition, [$issue->id => ['quantity_received' => 2]], $this->storekeeper);
    }

    public function test_legacy_issue_records_a_handover_and_close_marks_lines_closed(): void
    {
        $requisition = $this->approvedRequisition();
        $soap = $requisition->items->firstWhere('inventory_item_id', $this->soap->id);

        app(RequisitionService::class)->issue($soap, 1);

        $this->assertSame(1, RequisitionIssue::query()->where('requisition_item_id', $soap->id)->count());
        $this->assertSame(RequisitionStatus::PartiallyIssued, $requisition->fresh()->status);

        app(RequisitionService::class)->close($requisition->fresh('items'), 'No more needed');

        $requisition->refresh();
        $this->assertSame(RequisitionStatus::Closed, $requisition->status);
        $this->assertTrue($requisition->items->every(fn ($item) => $item->outstandingQuantity() === 0));
    }

    public function test_issue_counter_queue_is_scoped_to_the_keepers_stores(): void
    {
        $mine = $this->approvedRequisition();
        $otherStore = Store::factory()->create(['branch_id' => $this->branch->id, 'code' => 'LIN']);
        $otherStore->keepers()->attach(User::factory()->create()->id);
        Store::query()->findOrFail($mine->store_id)->keepers()->attach($this->storekeeper->id);

        $theirs = Requisition::factory()->create([
            'branch_id' => $this->branch->id,
            'store_id' => $otherStore->id,
            'status' => RequisitionStatus::Approved,
        ]);

        $queue = Requisition::query()->issuableBy($this->storekeeper)->pluck('id');

        $this->assertTrue($queue->contains($mine->id));
        $this->assertFalse($queue->contains($theirs->id));

        $manager = User::factory()->create();
        Permission::findOrCreate('manage_all_stores', 'web');
        $manager->givePermissionTo('manage_all_stores');

        $this->assertTrue(Requisition::query()->issuableBy($manager)->pluck('id')->contains($theirs->id));
    }

    public function test_signature_image_is_served_only_to_permitted_users(): void
    {
        $requisition = $this->approvedRequisition();
        $issue = app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector())->first();

        $url = route('inventory.requisition-issues.signature', $issue);

        $this->actingAs($this->requestor)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    public function test_voucher_pdf_renders_with_handovers_and_signatures(): void
    {
        $requisition = $this->approvedRequisition();
        app(RequisitionIssueService::class)->issueHandover($requisition, [
            ['requisition_item_id' => $this->line($requisition, $this->gloves), 'quantity' => 1, 'short_issue_reason' => 'out_of_stock', 'remainder_action' => 'backorder'],
            ['requisition_item_id' => $this->line($requisition, $this->soap), 'quantity' => 2],
        ], $this->collector());

        $html = view('inventory::pdf.requisition-voucher', [
            'requisition' => $requisition->fresh(['items.inventoryItem', 'issues.inventoryItem', 'issues.collectedBy', 'store']),
            'handovers' => collect([[
                'issues' => $requisition->issues()->with('inventoryItem')->get(),
                'first' => $requisition->issues()->first(),
                'signature' => 'data:image/png;base64,AAAA',
            ]]),
            'receivedByItem' => collect(),
        ])->render();

        $this->assertStringContainsString('Hand-overs', $html);
        $this->assertStringContainsString('Rest backordered', $html);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $html);

        $pdf = app(RequisitionVoucherPdfService::class)->render($requisition->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
