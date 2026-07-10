<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Classes\Support\Feature;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\StockTransferItem;

class InterBranchTransferService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
        protected DocumentNumberingService $numbering,
    ) {}

    public function create(array $data): StockTransfer
    {
        $this->ensureTransfersEnabled();

        $transfer = StockTransfer::create([
            'transfer_number' => $this->numbering->generate('TRF', $data['from_branch_id']),
            'from_branch_id' => $data['from_branch_id'],
            'to_branch_id' => $data['to_branch_id'],
            'status' => StockTransferStatus::Draft,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            $transfer->items()->create([
                'inventory_item_id' => $item['inventory_item_id'],
                'quantity_requested' => $item['quantity_requested'],
            ]);
        }

        return $transfer->load('items');
    }

    public function ship(StockTransfer $transfer, array $shippedQuantities): void
    {
        $this->ensureTransfersEnabled();

        DB::transaction(function () use ($transfer, $shippedQuantities) {
            foreach ($transfer->items as $item) {
                $qty = $shippedQuantities[$item->id] ?? $item->quantity_requested;

                $item->update(['quantity_shipped' => $qty]);

                // Decrement from source dispensary and increment in-transit at destination (FEFO, lot preserved)
                $this->stockLedger->transferQuantity(
                    itemId: $item->inventory_item_id,
                    fromBranchId: $transfer->from_branch_id,
                    fromLocation: StockLocationType::Dispensary,
                    fromDepartmentId: null,
                    fromStockTransferId: null,
                    toBranchId: $transfer->to_branch_id,
                    toLocation: StockLocationType::InTransit,
                    toDepartmentId: null,
                    toStockTransferId: $transfer->id,
                    qty: $qty,
                    transactionType: TransactionType::TransferShip,
                    reference: $item,
                );
            }

            $transfer->update([
                'status' => StockTransferStatus::Shipped,
                'shipped_by' => Auth::id(),
                'shipped_at' => now(),
            ]);
        });
    }

    public function receive(StockTransfer $transfer, array $receivedQuantities): void
    {
        $this->ensureTransfersEnabled();

        DB::transaction(function () use ($transfer, $receivedQuantities) {
            foreach ($transfer->items as $item) {
                $remaining = ($item->quantity_shipped ?? 0) - $item->quantity_received;
                $qty = $receivedQuantities[$item->id] ?? $remaining;

                if ($qty > $remaining) {
                    throw new \RuntimeException(
                        "Cannot receive {$qty}, only {$remaining} remaining in transit for item {$item->id}."
                    );
                }

                // Move from in-transit to destination dispensary (lot preserved)
                $this->stockLedger->transferQuantity(
                    itemId: $item->inventory_item_id,
                    fromBranchId: $transfer->to_branch_id,
                    fromLocation: StockLocationType::InTransit,
                    fromDepartmentId: null,
                    fromStockTransferId: $transfer->id,
                    toBranchId: $transfer->to_branch_id,
                    toLocation: StockLocationType::Dispensary,
                    toDepartmentId: null,
                    toStockTransferId: null,
                    qty: $qty,
                    transactionType: TransactionType::TransferReceive,
                    reference: $item,
                );

                $item->increment('quantity_received', $qty);
            }

            $allReceived = $transfer->items->every(
                fn (StockTransferItem $i) => $i->quantity_received >= ($i->quantity_shipped ?? 0)
            );

            $anyReceived = $transfer->items->contains(
                fn (StockTransferItem $i) => $i->quantity_received > 0
            );

            $newStatus = match (true) {
                $allReceived => StockTransferStatus::Received,
                $anyReceived => StockTransferStatus::PartiallyReceived,
                default => $transfer->status,
            };

            $transfer->update([
                'status' => $newStatus,
                'received_by' => Auth::id(),
                'received_at' => now(),
            ]);
        });
    }

    public function close(StockTransfer $transfer, string $reason): void
    {
        $this->ensureTransfersEnabled();

        DB::transaction(function () use ($transfer, $reason): void {
            foreach ($transfer->items as $item) {
                $remaining = ($item->quantity_shipped ?? 0) - $item->quantity_received;

                if ($remaining <= 0) {
                    continue;
                }

                $this->stockLedger->lockAndDecrement(
                    itemId: $item->inventory_item_id,
                    branchId: $transfer->to_branch_id,
                    locationType: StockLocationType::InTransit,
                    departmentId: null,
                    stockTransferId: $transfer->id,
                    qty: $remaining,
                    transactionType: TransactionType::Adjust,
                    reference: $item,
                );
            }

            $transfer->update([
                'status' => StockTransferStatus::Closed,
                'closed_reason' => $reason,
            ]);
        });
    }

    public function cancel(StockTransfer $transfer): void
    {
        $hasShipped = $transfer->items()->whereNotNull('quantity_shipped')->exists();

        if ($hasShipped) {
            throw new \RuntimeException('Cannot cancel a transfer after shipping. Use close instead.');
        }

        $transfer->update(['status' => StockTransferStatus::Cancelled]);
    }

    protected function ensureTransfersEnabled(): void
    {
        if (! Feature::interBranchTransfersEnabled()) {
            throw new \RuntimeException('Inter-branch stock transfers are disabled.');
        }
    }
}
