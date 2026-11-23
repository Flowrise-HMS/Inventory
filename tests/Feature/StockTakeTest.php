<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\Pdf\StockTakePdfService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Classes\Services\StockTakeService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages\CreateStockTake;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages\ViewStockTake;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\RelationManagers\StockTakeLinesRelationManager;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockTake;
use Modules\Inventory\Models\StockTakeLine;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Policies\StockTakePolicy;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockTakeTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Store $store;

    private User $counter;

    private User $supervisor;

    private InventoryItem $gloves;

    private InventoryItem $soap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        $this->branch = Branch::factory()->create();
        $this->store = Store::mainForBranch($this->branch->id);
        $this->counter = User::factory()->create();
        $this->supervisor = User::factory()->create();
        $this->gloves = InventoryItem::factory()->create(['name' => 'Examination Gloves']);
        $this->soap = InventoryItem::factory()->create(['name' => 'Liquid Soap']);

        $this->receive($this->gloves, 10, 'G-1', now()->addYear()->toDateString());
        $this->receive($this->gloves, 4, 'G-2', now()->addYears(2)->toDateString());
        $this->receive($this->soap, 6);

        $this->actingAs($this->counter);
    }

    private function receive(InventoryItem $item, int $qty, ?string $lot = null, ?string $expiry = null): void
    {
        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $item->id,
            branchId: $this->branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: $qty,
            transactionType: TransactionType::Receive,
            lotNumber: $lot,
            expiryDate: $expiry,
            storeId: $this->store->id,
        );
    }

    private function onHand(InventoryItem $item, ?string $lot = null): int
    {
        return (int) StockBalance::query()
            ->where('store_id', $this->store->id)
            ->where('inventory_item_id', $item->id)
            ->when($lot === null, fn ($q) => $q->whereNull('lot_number'), fn ($q) => $q->where('lot_number', $lot))
            ->sum('quantity_on_hand');
    }

    private function line(StockTake $take, InventoryItem $item, ?string $lot = null): StockTakeLine
    {
        return $take->lines()
            ->where('inventory_item_id', $item->id)
            ->when($lot === null, fn ($q) => $q->whereNull('lot_number'), fn ($q) => $q->where('lot_number', $lot))
            ->sole();
    }

    private function grantManageAllStores(User $user): void
    {
        Permission::findOrCreate('manage_all_stores', 'web');
        $user->givePermissionTo('manage_all_stores');
    }

    public function test_starting_snapshots_every_lot_and_blocks_a_second_open_count(): void
    {
        $take = app(StockTakeService::class)->start($this->store, isBlind: true);

        $this->assertStringStartsWith('STK-', $take->reference);
        $this->assertSame(StockTakeStatus::InProgress, $take->status);
        $this->assertSame(3, $take->lines()->count());
        $this->assertSame(10, $this->line($take, $this->gloves, 'G-1')->system_quantity);
        $this->assertSame(6, $this->line($take, $this->soap)->system_quantity);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has a stock-take in progress');
        app(StockTakeService::class)->start($this->store);
    }

    public function test_approval_posts_variances_and_keeps_issues_made_during_the_count(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store);

        // 3 packs of G-1 are issued while people count.
        app(StockLedgerService::class)->lockAndDecrementExact(
            itemId: $this->gloves->id,
            branchId: $this->branch->id,
            locationType: StockLocationType::Dispensary,
            qty: 3,
            transactionType: TransactionType::Issue,
            lotNumber: 'G-1',
            expiryDate: now()->addYear()->toDateString(),
            storeId: $this->store->id,
        );

        $service->recordCount($this->line($take, $this->gloves, 'G-1'), 9, 'One pack damaged');
        $service->recordCount($this->line($take, $this->gloves, 'G-2'), 4);
        $service->recordCount($this->line($take, $this->soap), 8, 'Delivery not booked in');
        $service->addFoundItem($take, $this->gloves->id, 'G-9', now()->addMonths(6)->toDateString(), 2, 'Box behind shelf');

        $service->submit($take);
        $this->assertSame(StockTakeStatus::Submitted, $take->fresh()->status);

        $service->approve($take->fresh(), $this->supervisor);

        $this->assertSame(StockTakeStatus::Approved, $take->fresh()->status);
        $this->assertSame(6, $this->onHand($this->gloves, 'G-1'), '10 snapshot − 3 issued − 1 short = 6.');
        $this->assertSame(4, $this->onHand($this->gloves, 'G-2'));
        $this->assertSame(8, $this->onHand($this->soap));
        $this->assertSame(2, $this->onHand($this->gloves, 'G-9'));

        $posted = $take->lines()->whereNotNull('adjustment_transaction_id')->count();
        $this->assertSame(3, $posted, 'Only lines with a difference are adjusted.');
        $this->assertSame(
            TransactionType::Adjust,
            InventoryTransaction::query()->findOrFail($this->line($take, $this->soap)->adjustment_transaction_id)->transaction_type,
        );
    }

    public function test_submit_needs_every_line_counted_and_reasons_on_an_open_count(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store, isBlind: false);

        try {
            $service->submit($take);
            $this->fail('Uncounted lines must block submission.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('3 line(s) have not been counted', $e->getMessage());
        }

        $service->recordCount($this->line($take, $this->gloves, 'G-1'), 10);
        $service->recordCount($this->line($take, $this->gloves, 'G-2'), 4);
        $service->recordCount($this->line($take, $this->soap), 5);

        try {
            $service->submit($take);
            $this->fail('Differences need a reason.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('without a reason', $e->getMessage());
        }

        $service->recordReason($this->line($take, $this->soap), 'Leaking bottle discarded');
        $service->submit($take);

        $this->assertSame(StockTakeStatus::Submitted, $take->fresh()->status);
    }

    public function test_blind_count_submits_without_reasons_but_the_approver_must_add_them(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store, isBlind: true);

        $take->lines()->each(fn (StockTakeLine $line) => $service->recordCount($line, $line->system_quantity));
        $service->recordCount($this->line($take, $this->soap), 5);
        $service->submit($take);

        try {
            $service->approve($take->fresh(), $this->supervisor);
            $this->fail('Approval needs reasons for differences.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Add a reason', $e->getMessage());
        }

        $service->recordReason($this->line($take, $this->soap), 'Expired bottle removed');
        $service->approve($take->fresh(), $this->supervisor);

        $this->assertSame(5, $this->onHand($this->soap));
    }

    public function test_the_submitter_cannot_approve_their_own_count(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store);
        $take->lines()->each(fn (StockTakeLine $line) => $service->recordCount($line, $line->system_quantity));
        $service->submit($take, $this->counter);

        try {
            $service->approve($take->fresh(), $this->counter);
            $this->fail('Self-approval must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Someone other than', $e->getMessage());
        }

        $this->grantManageAllStores($this->counter);
        $service->approve($take->fresh(), $this->counter->fresh());

        $this->assertSame(StockTakeStatus::Approved, $take->fresh()->status);
    }

    public function test_approval_is_all_or_nothing_when_stock_moved_too_much(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store);

        // All soap issued during the count; counters still saw 3 on the shelf.
        app(StockLedgerService::class)->lockAndDecrementExact(
            itemId: $this->soap->id,
            branchId: $this->branch->id,
            locationType: StockLocationType::Dispensary,
            qty: 6,
            transactionType: TransactionType::Issue,
            storeId: $this->store->id,
        );

        $service->recordCount($this->line($take, $this->gloves, 'G-1'), 12, 'Extra box');
        $service->recordCount($this->line($take, $this->gloves, 'G-2'), 4);
        $service->recordCount($this->line($take, $this->soap), 3, 'Miscounted');
        $service->submit($take);

        try {
            $service->approve($take->fresh(), $this->supervisor);
            $this->fail('The soap variance cannot be posted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Liquid Soap', $e->getMessage());
            $this->assertStringContainsString('send it back for recount', $e->getMessage());
        }

        $this->assertSame(10, $this->onHand($this->gloves, 'G-1'), 'The gloves adjustment must roll back too.');
        $this->assertSame(StockTakeStatus::Submitted, $take->fresh()->status);

        $service->returnForRecount($take->fresh(), 'Recount soap');
        $this->assertSame(StockTakeStatus::InProgress, $take->fresh()->status);
        $this->assertSame('Recount soap', $take->fresh()->status_reason);

        $service->cancel($take->fresh(), 'Will redo next week');
        $this->assertSame(StockTakeStatus::Cancelled, $take->fresh()->status);
    }

    public function test_counts_are_locked_after_submission_and_found_items_cannot_duplicate_a_line(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store);

        try {
            $service->addFoundItem($take, $this->gloves->id, 'G-1', now()->addYear()->toDateString(), 1);
            $this->fail('Duplicate lot line must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already on the count sheet', $e->getMessage());
        }

        $take->lines()->each(fn (StockTakeLine $line) => $service->recordCount($line, $line->system_quantity));
        $service->submit($take);

        $this->expectException(\RuntimeException::class);
        $service->recordCount($this->line($take->fresh(), $this->soap), 1);
    }

    public function test_stock_takes_are_scoped_to_stores_the_user_works_with(): void
    {
        $take = app(StockTakeService::class)->start($this->store);
        $policy = new StockTakePolicy;

        foreach (['View StockTake', 'Update StockTake', 'approve_stock_take'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $keeper = User::factory()->create();
        $keeper->givePermissionTo(['View StockTake', 'Update StockTake']);
        $outsider = User::factory()->create();
        $outsider->givePermissionTo(['View StockTake', 'Update StockTake', 'approve_stock_take']);

        $this->store->keepers()->attach($keeper->id);
        Store::factory()->create(['branch_id' => $this->branch->id, 'code' => 'LIN'])->keepers()->attach($outsider->id);

        $this->assertTrue($policy->view($keeper, $take));
        $this->assertTrue($policy->update($keeper, $take));
        $this->assertFalse($policy->approve($keeper, $take), 'Counting does not include approving.');
        $this->assertFalse($policy->view($outsider, $take), 'Keepers of other stores cannot see this count.');
    }

    public function test_count_sheet_hides_system_quantities_on_a_blind_count_and_variance_report_renders(): void
    {
        $service = app(StockTakeService::class);
        $take = $service->start($this->store, isBlind: true);

        $html = view('inventory::pdf.stock-take-count-sheet', ['stockTake' => $take->load('lines.inventoryItem', 'store'), 'showSystem' => false])->render();
        $this->assertStringContainsString('Blind count', $html);
        $this->assertStringNotContainsString('>System<', $html);
        $this->assertStringStartsWith('%PDF', app(StockTakePdfService::class)->countSheet($take->fresh()));

        $take->lines()->each(fn (StockTakeLine $line) => $service->recordCount($line, $line->system_quantity));
        $service->recordCount($this->line($take, $this->soap), 4, 'Broken');
        $service->submit($take);

        $this->assertStringStartsWith('%PDF', app(StockTakePdfService::class)->varianceReport($take->fresh()));
    }

    public function test_storekeeper_starts_and_counts_from_the_screens(): void
    {
        Gate::before(fn (): bool => true);
        Filament::setCurrentPanel(Filament::getDefaultPanel());

        Livewire::test(CreateStockTake::class)
            ->fillForm(['store_id' => $this->store->id, 'is_blind' => true, 'notes' => 'Month-end count'])
            ->call('create')
            ->assertHasNoFormErrors();

        $take = StockTake::query()->where('store_id', $this->store->id)->sole();
        $this->assertTrue($take->is_blind);

        Livewire::test(ViewStockTake::class, ['record' => $take->getRouteKey()])->assertOk();

        $line = $this->line($take, $this->soap);

        Livewire::test(StockTakeLinesRelationManager::class, ['ownerRecord' => $take, 'pageClass' => ViewStockTake::class])
            ->assertOk()
            ->assertSee('Liquid Soap')
            ->call('updateTableColumnState', 'counted_quantity', (string) $line->getKey(), '5');

        $this->assertSame(5, $line->fresh()->counted_quantity);
        $this->assertSame($this->counter->id, $line->fresh()->counted_by);
    }
}
