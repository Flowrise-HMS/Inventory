<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Enums\LocationType;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Core\Models\Unit;
use Modules\Core\Settings\FeatureSettings;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ApiResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

$modules = ['Core', 'Patient', 'Pharmacy', 'Inventory'];

describe('inventory items', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'inventory-items',
        'subject' => 'InventoryItem',
        'scope' => 'inventory',
        'modules' => $modules,
        'branchIsolated' => false,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => InventoryItem::factory()->create($attributes),
        'store' => fn (TestCase $test, Branch $branch): array => ['name' => 'Gauze swab', 'sku' => 'GZ-'.fake()->unique()->numerify('#####'), 'category' => 'consumables', 'unit_id' => Unit::factory()->create()->id],
        'invalid' => [['name' => 'No sku', 'category' => 'consumables'], 'sku'],
        'update' => fn (TestCase $test, $record): array => ['description' => 'Sterile 10x10'],
        'destroy' => false,
    ]);
});

describe('suppliers', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'suppliers',
        'subject' => 'Supplier',
        'scope' => 'inventory',
        'modules' => $modules,
        'branchIsolated' => false,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => Supplier::factory()->create($attributes),
        'store' => fn (TestCase $test, Branch $branch): array => ['name' => 'MedSupply Ltd', 'email' => 'orders@medsupply.test'],
        'invalid' => [['name' => 'X', 'email' => 'not-email'], 'email'],
        'update' => fn (TestCase $test, $record): array => ['phone' => '+233200000000'],
    ]);
});

describe('stock balances', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'stock-balances',
        'subject' => 'StockBalance',
        'scope' => 'inventory',
        'modules' => $modules,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => StockBalance::factory()->create(['branch_id' => $branch->id, ...$attributes]),
    ]);
});

describe('stock transactions', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'stock-transactions',
        'subject' => 'InventoryTransaction',
        'scope' => 'inventory',
        'modules' => $modules,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => InventoryTransaction::factory()->create(['branch_id' => $branch->id, ...$attributes]),
    ]);
});

describe('purchase orders', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'purchase-orders',
        'subject' => 'PurchaseOrder',
        'scope' => 'inventory',
        'modules' => $modules,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => PurchaseOrder::factory()->create(['branch_id' => $branch->id, ...$attributes]),
        'store' => fn (TestCase $test, Branch $branch): array => [
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['inventory_item_id' => InventoryItem::factory()->create()->id, 'quantity_ordered' => 10]],
        ],
        'invalid' => [['items' => []], 'items'],
        'update' => fn (TestCase $test, $record): array => ['notes' => 'Deliver to main store'],
    ]);
});

describe('requisitions', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'requisitions',
        'subject' => 'Requisition',
        'scope' => 'inventory',
        'modules' => $modules,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => Requisition::factory()->create(['branch_id' => $branch->id, ...$attributes]),
        'update' => fn (TestCase $test, $record): array => ['notes' => 'Urgent'],
    ]);
});

describe('stock transfers', function () use ($modules): void {
    ApiResourceTestSuite::register([
        'uri' => 'stock-transfers',
        'subject' => 'StockTransfer',
        'scope' => 'inventory',
        'modules' => $modules,
        'make' => fn (TestCase $test, Branch $branch, array $attributes = []) => StockTransfer::factory()->create(['from_branch_id' => $branch->id, ...$attributes]),
    ]);
});

describe('inventory workflow', function () use ($modules): void {
    beforeEach(function () use ($modules): void {
        $this->requireModule('Api');
        $this->migrateModules($modules);
        $this->branch = Branch::factory()->create();
        $this->other = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        foreach (['PurchaseOrder', 'StockBalance', 'Requisition', 'StockTransfer'] as $subject) {
            foreach (['ViewAny', 'View', 'Create', 'Update'] as $action) {
                Permission::findOrCreate("{$action} {$subject}", 'web');
                $this->user->givePermissionTo("{$action} {$subject}");
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FeatureSettings::fake([
            'inventory_pharmacy_procurement' => true,
            'inventory_ward_requisitions' => true,
            'inventory_inter_branch_transfers' => true,
        ]);
        $this->withToken($this->user->createToken('t', ['inventory:write'])->plainTextToken);
    });

    it('orders, submits and receives stock with a lot', function (): void {
        $item = InventoryItem::factory()->create();
        $po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity_ordered' => 10]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/purchase-orders/{$po['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity_received' => 20]],
        ])->assertUnprocessable();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity_received' => 6, 'lot_number' => 'L1', 'expiry_date' => now()->addYear()->toDateString()]],
        ])->assertOk()->assertJsonPath('data.items.0.quantity_received', 6);

        expect(StockBalance::query()->where('inventory_item_id', $item->id)->where('branch_id', $this->branch->id)->sum('quantity_on_hand'))->toEqual(6);
    });

    it('adjusts a stock balance after a count', function (): void {
        $balance = StockBalance::factory()->create(['branch_id' => $this->branch->id, 'quantity_on_hand' => 100]);

        $this->postJson("/api/v1/stock-balances/{$balance->id}/adjust", ['quantity' => 100])->assertUnprocessable();
        $this->postJson("/api/v1/stock-balances/{$balance->id}/adjust", ['quantity' => 95, 'reason' => 'Count'])
            ->assertOk()
            ->assertJsonPath('data.quantity_on_hand', 95);
    });

    it('creates and declines a requisition', function (): void {
        $department = Department::factory()->create();
        $ward = Location::factory()->create(['branch_id' => $this->branch->id, 'type' => LocationType::ROOM, 'is_active' => true]);
        $department->locations()->attach($ward->id, ['is_primary' => true]);

        $id = $this->postJson('/api/v1/requisitions', [
            'department_id' => $department->id,
            'items' => [['inventory_item_id' => InventoryItem::factory()->create()->id, 'quantity_requested' => 3]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/requisitions/{$id}/decline", ['reason' => 'Over budget'])->assertOk()->assertJsonPath('data.decline_reason', 'Over budget');
    });

    it('lets only the sending branch ship and only the receiver receive', function (): void {
        $item = InventoryItem::factory()->create();
        $id = $this->postJson('/api/v1/stock-transfers', [
            'to_branch_id' => $this->other->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity_requested' => 2]],
        ])->assertCreated()->assertJsonPath('data.from_branch_id', $this->branch->id)->json('data.id');

        $this->postJson("/api/v1/stock-transfers/{$id}/receive")->assertUnprocessable();
        $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->branch->id, 'items' => [['inventory_item_id' => $item->id, 'quantity_requested' => 1]]])->assertUnprocessable();
    });
});
