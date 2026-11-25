<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages\ViewStockBalance;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Tests\TestCase;

/**
 * "Adjust stock" must change the row it was opened on, and only that row.
 */
class AdjustStockBalanceTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private InventoryItem $item;

    private StockBalance $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        Gate::before(fn (): bool => true);
        Filament::setCurrentPanel(Filament::getDefaultPanel());
        $this->actingAs(User::factory()->create());

        $this->branch = Branch::factory()->create();
        $this->item = InventoryItem::factory()->create();
        $this->main = $this->balance(['quantity_on_hand' => 50]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function balance(array $attributes): StockBalance
    {
        return StockBalance::factory()->create([
            'inventory_item_id' => $this->item->id,
            'branch_id' => $this->branch->id,
            'location_type' => StockLocationType::Dispensary->value,
            ...$attributes,
        ]);
    }

    private function adjust(StockBalance $balance, int $quantity): void
    {
        Livewire::test(ViewStockBalance::class, ['record' => $balance->getRouteKey()])
            ->callAction('adjust', data: ['new_quantity' => $quantity, 'reason' => 'Recount'])
            ->assertHasNoFormErrors();
    }

    public function test_adjusting_another_store_leaves_the_main_store_alone(): void
    {
        $linen = Store::factory()->create(['branch_id' => $this->branch->id, 'code' => 'LIN']);
        $balance = $this->balance(['store_id' => $linen->id, 'quantity_on_hand' => 8]);

        $this->adjust($balance, 5);
        $this->assertSame(5, $balance->fresh()->quantity_on_hand);

        $this->adjust($balance, 12);
        $this->assertSame(12, $balance->fresh()->quantity_on_hand);
        $this->assertSame(50, $this->main->fresh()->quantity_on_hand);
    }

    public function test_an_expired_lot_can_be_written_off_without_touching_other_lots(): void
    {
        $expired = $this->balance([
            'lot_number' => 'OLD',
            'expiry_date' => now()->subMonth()->toDateString(),
            'quantity_on_hand' => 6,
        ]);

        $this->adjust($expired, 0);

        $this->assertSame(0, $expired->fresh()->quantity_on_hand);
        $this->assertSame(50, $this->main->fresh()->quantity_on_hand);
        $this->assertSame(-6, InventoryTransaction::query()->where('inventory_item_id', $this->item->id)->sole()->delta);
    }

    public function test_reducing_the_row_without_a_lot_does_not_take_from_dated_lots(): void
    {
        $dated = $this->balance([
            'lot_number' => 'SOON',
            'expiry_date' => now()->addWeek()->toDateString(),
            'quantity_on_hand' => 9,
        ]);

        $this->adjust($this->main, 45);

        $this->assertSame(45, $this->main->fresh()->quantity_on_hand);
        $this->assertSame(9, $dated->fresh()->quantity_on_hand);
    }

    public function test_adjusting_ward_stock_changes_that_ward_only(): void
    {
        $department = Department::factory()->create();
        $ward = Location::factory()->create(['branch_id' => $this->branch->id]);
        $shared = ['location_type' => StockLocationType::Ward->value, 'department_id' => $department->id];
        $departmentRow = $this->balance([...$shared, 'quantity_on_hand' => 20]);
        $wardRow = $this->balance([...$shared, 'location_id' => $ward->id, 'quantity_on_hand' => 7]);

        $this->adjust($wardRow, 3);

        $this->assertSame(3, $wardRow->fresh()->quantity_on_hand);
        $this->assertSame(20, $departmentRow->fresh()->quantity_on_hand);
        $this->assertSame(50, $this->main->fresh()->quantity_on_hand);
    }
}
