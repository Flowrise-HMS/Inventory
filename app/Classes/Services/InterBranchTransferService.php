<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        DB::transaction(function () use ($transfer, $shippedQuantities) {
            foreach ($transfer->items as $item) {
                $qty = $shippedQuantities[$item->id] ?? $item->quantity_requested;

                $item->update(['quantity_shipped' => $qty]);

                // Decrement from source dispensary
                $this->stockLedger->lockAndDecrement(
                    itemId: $item->inventory_item_id,
                    branchId: $transfer->from_branch_id,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: $transfer->id,
                    qty: $qty,
                    transactionType: TransactionType::TransferShip,
                    reference: $item,
                );

                // Increment in-transit at destination branch
                $this->stockLedger->lockAndIncrement(
                    itemId: $item->inventory_item_id,
                    branchId: $transfer->to_branch_id,
                    locationType: StockLocationType::InTransit,
                    departmentId: null,
                    stockTransferId: $transfer->id,
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
        DB::transaction(function () use ($transfer, $receivedQuantities) {
            foreach ($transfer->items as $item) {
                $remaining = ($item->quantity_shipped ?? 0) - $item->quantity_received;
                $qty = $receivedQuantities[$item->id] ?? $remaining;

                if ($qty > $remaining) {
                    throw new \RuntimeException(
                        "Cannot receive {$qty}, only {$remaining} remaining in transit for item {$item->id}."
                    );
                }

                // Decrement from in-transit
                $this->stockLedger->lockAndDecrement(
                    itemId: $item->inventory_item_id,
                    branchId: $transfer->to_branch_id,
                    locationType: StockLocationType::InTransit,
                    departmentId: null,
                    stockTransferId: $transfer->id,
                    qty: $qty,
                    transactionType: TransactionType::TransferReceive,
                    reference: $item,
                );

                // Increment destination dispensary
                $this->stockLedger->lockAndIncrement(
                    itemId: $item->inventory_item_id,
                    branchId: $transfer->to_branch_id,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: null,
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
        $transfer->update([
            'status' => StockTransferStatus::Closed,
            'closed_reason' => $reason,
        ]);
    }

    public function cancel(StockTransfer $transfer): void
    {
        $hasShipped = $transfer->items()->whereNotNull('quantity_shipped')->exists();

        if ($hasShipped) {
            throw new \RuntimeException('Cannot cancel a transfer after shipping. Use close instead.');
        }

        $transfer->update(['status' => StockTransferStatus::Cancelled]);
    }
}
