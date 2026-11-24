<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Resources\FieldListTransformer;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;

/**
 * @group Inventory
 *
 * Internal requisitions from departments/wards to a store. Issuing (handover
 * with collector confirmation) is done at the store in the panel.
 */
class RequisitionController extends InventoryApiController
{
    protected array $with = ['items'];

    public function __construct(protected RequisitionService $requisitions) {}

    public static function model(): string
    {
        return Requisition::class;
    }

    public function fields(): array
    {
        return ['id', 'requisition_number', 'requestor_id', 'department_id', 'location_id', 'status', 'endorsed_by', 'endorsed_at',
            'approved_by', 'approved_at', 'declined_by', 'declined_at', 'decline_reason', 'issued_at', 'closed_at', 'notes', 'created_at'];
    }

    public function filters(): array
    {
        return ['status' => 'status', 'department_id' => 'department_id', 'location_id' => 'location_id'];
    }

    public function searchable(): array
    {
        return ['requisition_number'];
    }

    public function storeRules(): array
    {
        return [
            'department_id' => ['required', 'uuid'],
            'location_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'items.*.quantity_requested' => ['required', 'integer', 'min:1'],
        ];
    }

    public function updateRules(?Model $record = null): array
    {
        return ['notes' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }

    protected function createRecord(array $data): Model
    {
        return $this->domain(fn () => $this->requisitions->create($data));
    }

    protected function present(Model $record, Request $request): array
    {
        return [
            ...parent::present($record, $request),
            'items' => $record->items->map(fn (RequisitionItem $item): array => FieldListTransformer::using($item, [
                'id', 'inventory_item_id', 'quantity_requested', 'quantity_approved', 'quantity_issued',
            ])->toArray($request))->values()->all(),
        ];
    }

    /**
     * Endorse as ward incharge.
     */
    public function endorse(string $id): JsonResponse
    {
        return $this->act($id, 'update', function (Requisition $requisition): Requisition {
            $this->domain(fn () => $this->requisitions->endorse($requisition));

            return $requisition->refresh();
        });
    }

    /**
     * Approve, optionally with reduced quantities.
     *
     * @bodyParam quantities object Map of requisition item id to approved quantity. Example: {"9d0c...": 5}
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['quantities' => ['nullable', 'array'], 'quantities.*' => ['integer', 'min:0']]);

        return $this->act($id, 'update', function (Requisition $requisition) use ($data): Requisition {
            $this->domain(fn () => $this->requisitions->approve($requisition, $data['quantities'] ?? null));

            return $requisition->refresh();
        });
    }

    /**
     * Decline the requisition.
     *
     * @bodyParam reason string required Example: Not in stock budget.
     */
    public function decline(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($id, 'update', function (Requisition $requisition) use ($data): Requisition {
            $this->requisitions->decline($requisition, $data['reason']);

            return $requisition->refresh();
        });
    }

    /**
     * Cancel a requisition that is not finished.
     */
    public function cancel(string $id): JsonResponse
    {
        return $this->act($id, 'update', function (Requisition $requisition): Requisition {
            $this->requisitions->cancel($requisition);

            return $requisition->refresh();
        });
    }
}
