<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\PurchaseOrderReceipt;
use Modules\Inventory\Models\PurchaseOrderReceiptItem;

class PurchaseOrderService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
        protected DocumentNumberingService $numbering,
    ) {}

    public function create(array $data): PurchaseOrder
    {
        $branchId = $data['branch_id'];

        $po = PurchaseOrder::create([
            'supplier_id' => $data['supplier_id'],
            'branch_id' => $branchId,
            'po_number' => $this->numbering->generate('PO', $branchId),
            'status' => 'draft',
            'ordered_at' => $data['ordered_at'] ?? now(),
            'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'inventory_item_id' => $item['inventory_item_id'],
                'quantity_ordered' => $item['quantity_ordered'],
                'expected_unit_price' => $item['expected_unit_price'] ?? null,
            ]);
        }

        return $po->load('items');
    }

    public function submit(PurchaseOrder $po): void
    {
        $po->update([
            'status' => PurchaseOrderStatus::Submitted,
            'submitted_by' => Auth::id(),
        ]);
    }

    public function receive(PurchaseOrder $po, array $receiptData): PurchaseOrderReceipt
    {
        return DB::transaction(function () use ($po, $receiptData) {
            $receipt = PurchaseOrderReceipt::create([
                'purchase_order_id' => $po->id,
                'received_by' => Auth::id(),
                'received_at' => $receiptData['received_at'] ?? now(),
                'notes' => $receiptData['notes'] ?? null,
            ]);

            foreach ($receiptData['items'] as $item) {
                $poItem = PurchaseOrderItem::query()
                    ->where('id', $item['purchase_order_item_id'])
                    ->where('purchase_order_id', $po->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $remaining = $poItem->quantity_ordered - $poItem->quantity_received;

                if ($item['quantity_received'] > $remaining) {
                    throw new \RuntimeException(
                        "Cannot receive more than ordered. Item {$poItem->id}: ".
                        "ordered {$poItem->quantity_ordered}, already received {$poItem->quantity_received}"
                    );
                }

                $receiptItem = PurchaseOrderReceiptItem::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'quantity_received' => $item['quantity_received'],
                    'lot_number' => $item['lot_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null,
                ]);

                $poItem->increment('quantity_received', $item['quantity_received']);

                $this->stockLedger->lockAndIncrement(
                    itemId: $poItem->inventory_item_id,
                    branchId: $po->branch_id,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: null,
                    qty: $item['quantity_received'],
                    transactionType: TransactionType::Receive,
                    reference: $receiptItem,
                );
            }

            $allItems = $po->items()->lockForUpdate()->get();

            $allFullyReceived = $allItems->every(fn (PurchaseOrderItem $i) => $i->quantity_received >= $i->quantity_ordered);

            $anyReceived = $allItems->contains(fn (PurchaseOrderItem $i) => $i->quantity_received > 0);

            $newStatus = match (true) {
                $allFullyReceived => PurchaseOrderStatus::Received,
                $anyReceived => PurchaseOrderStatus::PartiallyReceived,
                default => $po->status,
            };

            $po->update(['status' => $newStatus]);

            return $receipt->load('items');
        });
    }

    public function closeRemaining(PurchaseOrder $po, ?string $reason = null): void
    {
        $po->update([
            'status' => PurchaseOrderStatus::Closed,
            'notes' => $reason ? ($po->notes."\nClosed: ".$reason) : $po->notes,
        ]);
    }

    public function cancel(PurchaseOrder $po): void
    {
        $hasReceived = $po->items()->where('quantity_received', '>', 0)->exists();

        if ($hasReceived) {
            throw new \RuntimeException('Cannot cancel a PO with partial receipts. Use closeRemaining instead.');
        }

        $po->update(['status' => PurchaseOrderStatus::Cancelled]);
    }
}
