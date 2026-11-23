<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionIssueService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Actions\IssueHandoverAction;
use Modules\Inventory\Filament\Clusters\Inventory\Pages\IssueCounter;
use Modules\Inventory\Filament\Widgets\MyWardRequestsWidget;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionIssue;
use Tests\TestCase;

class IssueCounterAndReceiptUiTest extends TestCase
{
    use DatabaseTransactions;

    private User $requestor;

    private User $storekeeper;

    private Branch $branch;

    private InventoryItem $gloves;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        // Shield permissions are not seeded in the test database.
        Gate::before(fn (): bool => true);
        Filament::setCurrentPanel(Filament::getDefaultPanel());
        Storage::fake('local');

        $this->requestor = User::factory()->create();
        $this->storekeeper = User::factory()->create();
        $this->branch = Branch::factory()->create();
        $this->gloves = InventoryItem::factory()->create(['name' => 'Examination Gloves']);

        app(StockLedgerService::class)->addOpeningStock($this->gloves->id, $this->branch->id, 1);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(30, 15);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function approvedRequisition(): Requisition
    {
        $department = Department::factory()->create();
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);
        $department->locations()->attach($location->id, ['is_primary' => true]);

        $this->actingAs($this->requestor);
        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $this->branch->id,
            'department_id' => $department->id,
            'items' => [['inventory_item_id' => $this->gloves->id, 'quantity_requested' => 3]],
        ]);
        app(RequisitionService::class)->approve($requisition);

        return $requisition->fresh('items');
    }

    public function test_hand_over_defaults_to_what_the_store_has_and_suggests_a_backorder(): void
    {
        $requisition = $this->approvedRequisition();

        $lines = IssueHandoverAction::defaultLines($requisition);

        $this->assertCount(1, $lines);
        $this->assertSame(3, $lines[0]['outstanding']);
        $this->assertSame(1, $lines[0]['on_hand']);
        $this->assertSame(1, $lines[0]['quantity']);
        $this->assertSame('out_of_stock', $lines[0]['short_issue_reason']);
        $this->assertSame('backorder', $lines[0]['remainder_action']);
    }

    public function test_storekeeper_issues_from_the_issue_counter_with_a_signature(): void
    {
        $requisition = $this->approvedRequisition();
        $line = IssueHandoverAction::defaultLines($requisition)[0];

        $this->actingAs($this->storekeeper);

        Livewire::test(IssueCounter::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$requisition])
            ->callAction(TestAction::make('issue_handover')->table($requisition), data: [
                'lines' => [$line],
                'collected_by_name' => 'Ama (ward aide)',
                'signature' => $this->signature(),
            ])
            ->assertHasNoFormErrors();

        $issue = RequisitionIssue::query()->where('requisition_id', $requisition->id)->sole();

        $this->assertSame(1, $issue->quantity_issued);
        $this->assertSame('Ama (ward aide)', $issue->collected_by_name);
        $this->assertTrue($issue->hasSignature());
        $this->assertSame(RequisitionStatus::PartiallyIssued, $requisition->fresh()->status);
    }

    public function test_issue_counter_rejects_a_hand_over_without_a_signature(): void
    {
        $requisition = $this->approvedRequisition();
        $line = IssueHandoverAction::defaultLines($requisition)[0];

        $this->actingAs($this->storekeeper);

        Livewire::test(IssueCounter::class)
            ->callAction(TestAction::make('issue_handover')->table($requisition), data: [
                'lines' => [$line],
                'collected_by_name' => 'Ama (ward aide)',
                'signature' => null,
            ])
            ->assertHasFormErrors(['signature' => 'required']);

        $this->assertSame(0, RequisitionIssue::query()->count());
    }

    public function test_ward_confirms_receipt_from_the_widget(): void
    {
        $requisition = $this->approvedRequisition();

        $this->actingAs($this->storekeeper);
        $issue = app(RequisitionIssueService::class)->issueHandover($requisition, [[
            'requisition_item_id' => (string) $requisition->items->first()->id,
            'quantity' => 1,
            'short_issue_reason' => 'out_of_stock',
            'remainder_action' => 'backorder',
        ]], ['name' => 'Ama', 'signature' => $this->signature()])->first();

        $this->actingAs($this->requestor);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionVisible(TestAction::make('confirm_receipt')->table($requisition))
            ->callAction(TestAction::make('confirm_receipt')->table($requisition), data: [
                'receipts' => [[
                    'issue_id' => (string) $issue->id,
                    'quantity_issued' => 1,
                    'summary' => 'x',
                    'quantity_received' => 1,
                ]],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(ReceiptStatus::Confirmed, $issue->fresh()->receipt_status);
    }

    public function test_confirm_receipt_is_hidden_from_the_storekeeper_who_issued(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->update(['requestor_id' => $this->storekeeper->id]);

        $this->actingAs($this->storekeeper);
        app(RequisitionIssueService::class)->issueHandover($requisition->fresh(), [[
            'requisition_item_id' => (string) $requisition->items->first()->id,
            'quantity' => 1,
            'short_issue_reason' => 'out_of_stock',
            'remainder_action' => 'backorder',
        ]], ['name' => 'Ama', 'signature' => $this->signature()]);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionHidden(TestAction::make('confirm_receipt')->table($requisition));
    }
}
