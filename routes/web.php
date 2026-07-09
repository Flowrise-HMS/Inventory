<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\AdjustmentVoucherPdfController;
use Modules\Inventory\Http\Controllers\GrnPdfController;
use Modules\Inventory\Http\Controllers\InventoryReportCsvController;
use Modules\Inventory\Http\Controllers\RequisitionVoucherPdfController;
use Modules\Inventory\Http\Controllers\StockCardPdfController;
use Modules\Inventory\Http\Controllers\TransferNotePdfController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/inventory/purchase-orders/{purchaseOrder}/grn', GrnPdfController::class)
        ->name('inventory.purchase-orders.grn');

    Route::get('/inventory/requisitions/{requisition}/voucher', RequisitionVoucherPdfController::class)
        ->name('inventory.requisitions.voucher');

    Route::get('/inventory/stock-transfers/{transfer}/note', TransferNotePdfController::class)
        ->name('inventory.stock-transfers.note');

    Route::get('/inventory/transactions/{transaction}/adjustment-voucher', AdjustmentVoucherPdfController::class)
        ->name('inventory.transactions.adjustment-voucher');

    Route::get('/inventory/items/{item}/stock-card', StockCardPdfController::class)
        ->name('inventory.items.stock-card');

    Route::get('/inventory/reports/csv', InventoryReportCsvController::class)
        ->name('inventory.reports.csv');
});
