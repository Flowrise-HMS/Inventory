<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\Supplier;
use Tests\TestCase;

class CreatePurchaseOrderFormTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        // Filament resource policies are gated by Shield permissions that aren't
        // seeded in the test database; bypass authorization for these UI tests.
        Gate::before(fn (): bool => true);

        $user = User::factory()->create();
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getDefaultPanel());
    }

    public function test_can_create_purchase_order_with_inline_repeater_items(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $itemOne = InventoryItem::factory()->create();
        $itemTwo = InventoryItem::factory()->create();

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'ordered_at' => now(),
                'items' => [
                    [
                        'inventory_item_id' => $itemOne->id,
                        'quantity_ordered' => 25,
                        'expected_unit_price' => 12.5,
                    ],
                    [
                        'inventory_item_id' => $itemTwo->id,
                        'quantity_ordered' => 10,
                        'expected_unit_price' => null,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $po = PurchaseOrder::query()->where('branch_id', $branch->id)->firstOrFail();

        $this->assertNotNull($po->po_number);
        $this->assertEquals('draft', $po->status->value);
        $this->assertCount(2, $po->items);
        $this->assertEquals(25, $po->items->firstWhere('inventory_item_id', $itemOne->id)->quantity_ordered);
        $this->assertEquals(10, $po->items->firstWhere('inventory_item_id', $itemTwo->id)->quantity_ordered);
    }

    public function test_repeater_requires_at_least_one_item(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'ordered_at' => now(),
                'items' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['items']);
    }

    public function test_edit_form_hides_items_repeater_once_purchase_order_is_submitted(): void
    {
        $po = PurchaseOrder::factory()->create(['status' => 'submitted']);

        Livewire::test(EditPurchaseOrder::class, ['record' => $po->getKey()])
            ->assertFormFieldIsHidden('items');
    }

    public function test_edit_form_shows_items_repeater_while_purchase_order_is_draft(): void
    {
        $po = PurchaseOrder::factory()->create(['status' => 'draft']);

        Livewire::test(EditPurchaseOrder::class, ['record' => $po->getKey()])
            ->assertFormFieldIsVisible('items');
    }
}
