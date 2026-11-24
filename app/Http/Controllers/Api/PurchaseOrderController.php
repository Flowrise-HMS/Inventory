<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Resources\FieldListTransformer;
use Modules\Inventory\Classes\Services\PurchaseOrderService;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;

/**
 * @group Inventory
 *
 * Purchase orders: draft → submit → receive (partial or full, with lots) → close.
 */
class PurchaseOrderController extends InventoryApiController
{
    protected array $with = ['items'];

    public function __construct(protected PurchaseOrderService $orders) {}

    public static function model(): string
    {
        return PurchaseOrder::class;
    }

    public function fields(): array
    {
        return ['id', 'po_number', 'supplier_id', 'status', 'ordered_at', 'expected_delivery_at', 'submitted_by', 'notes', 'created_at', 'updated_at'];
    }

    public function filters(): array
    {
        return ['status' => 'status', 'supplier_id' => 'supplier_id'];
    }

    public function searchable(): array
    {
        return ['po_number'];
    }

    public function storeRules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'expected_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.expected_unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function updateRules(?Model $record = null): array
    {
        return [
            'expected_delivery_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function createRecord(array $data): Model
    {
        return $this->orders->create($data);
    }

    protected function present(Model $record, Request $request): array
    {
        return [
            ...parent::present($record, $request),
            'items' => $record->items->map(fn (PurchaseOrderItem $item): array => FieldListTransformer::using($item, [
                'id', 'inventory_item_id', 'quantity_ordered', 'quantity_received', 'expected_unit_price',
            ])->toArray($request))->values()->all(),
        ];
    }

    /**
     * Submit the order to the supplier.
     */
    public function submit(string $id): JsonResponse
    {
        return $this->act($id, 'update', function (PurchaseOrder $order): PurchaseOrder {
            $this->requireStatus($order, [PurchaseOrderStatus::Draft], 'Only draft orders can be submitted.');
            $this->orders->submit($order);

            return $order->refresh();
        });
    }

    /**
     * Receive goods against the order (adds stock with lot and expiry).
     *
     * @bodyParam items object[] required Lines received.
     * @bodyParam items[].purchase_order_item_id string required Example: 9d0c6b0e-...
     * @bodyParam items[].quantity_received integer required Example: 10
     * @bodyParam items[].lot_number string Example: LOT-2026-11
     * @bodyParam items[].expiry_date string Example: 2028-06-30
     * @bodyParam items[].unit_price number Example: 2.50
     * @bodyParam notes string Example: Delivered by courier.
     */
    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'uuid'],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->act($id, 'update', function (PurchaseOrder $order) use ($data): PurchaseOrder {
            $this->requireStatus($order, [PurchaseOrderStatus::Submitted, PurchaseOrderStatus::PartiallyReceived], 'Only submitted or partially received orders can be received.');
            $this->domain(fn () => $this->orders->receive($order, $data));

            return $order->refresh();
        });
    }

    /**
     * Close the order, abandoning anything not yet received.
     *
     * @bodyParam reason string Example: Supplier out of stock.
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->act($id, 'update', function (PurchaseOrder $order) use ($data): PurchaseOrder {
            $this->requireStatus($order, [PurchaseOrderStatus::Submitted, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received], 'Only submitted or received orders can be closed.');
            $this->orders->closeRemaining($order, $data['reason'] ?? null);

            return $order->refresh();
        });
    }

    /**
     * Cancel an order with nothing received.
     */
    public function cancel(string $id): JsonResponse
    {
        return $this->act($id, 'update', function (PurchaseOrder $order): PurchaseOrder {
            $this->requireStatus($order, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted], 'Only draft or submitted orders can be cancelled.');
            $this->domain(fn () => $this->orders->cancel($order));

            return $order->refresh();
        });
    }
}
