<?php

use Modules\Core\Services\ApiRouteRegistrar;
use Modules\Inventory\Http\Controllers\Api\InventoryItemController;
use Modules\Inventory\Http\Controllers\Api\InventoryTransactionController;
use Modules\Inventory\Http\Controllers\Api\PurchaseOrderController;
use Modules\Inventory\Http\Controllers\Api\RequisitionController;
use Modules\Inventory\Http\Controllers\Api\StockBalanceController;
use Modules\Inventory\Http\Controllers\Api\StockTransferController;
use Modules\Inventory\Http\Controllers\Api\SupplierController;

/*
| Inventory REST API (`/api/v1`, Sanctum + branch, token scope "inventory").
| Stock only changes through the ledger: adjustments, receipts, transfers.
*/
ApiRouteRegistrar::register(scope: 'inventory', routes: function (): void {
    InventoryItemController::routes('inventory-items');
    SupplierController::routes('suppliers');
    StockBalanceController::routes('stock-balances', ['index', 'show'], ['adjust' => 'adjust']);
    InventoryTransactionController::routes('stock-transactions', ['index', 'show']);
    PurchaseOrderController::routes('purchase-orders', ['index', 'show', 'store', 'update'], [
        'submit' => 'submit', 'receive' => 'receive', 'close' => 'close', 'cancel' => 'cancel',
    ]);
    RequisitionController::routes('requisitions', ['index', 'show', 'store', 'update'], [
        'endorse' => 'endorse', 'approve' => 'approve', 'decline' => 'decline', 'cancel' => 'cancel',
    ]);
    StockTransferController::routes('stock-transfers', ['index', 'show', 'store', 'update'], [
        'ship' => 'ship', 'receive' => 'receive', 'cancel' => 'cancel',
    ]);
});
