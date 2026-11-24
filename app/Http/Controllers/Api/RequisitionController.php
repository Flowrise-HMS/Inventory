<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Http\Resources\FieldListTransformer;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        return $this->act($id, 'view', function (Requisition $requisition): Requisition {
            $user = auth()->user();

            if (blank($requisition->location_id) || ! app(WardAccessService::class)->isWardIncharge($user, (string) $requisition->location_id)) {
                throw new AccessDeniedHttpException('Only the ward incharge can endorse this requisition.');
            }

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

        return $this->act($id, 'approve', function (Requisition $requisition) use ($data): Requisition {
            $this->requireStatus($requisition, [RequisitionStatus::Pending], 'Only pending requisitions can be approved.');
            $this->assertWithinRequested($requisition, $data['quantities'] ?? []);
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

        return $this->act($id, 'approve', function (Requisition $requisition) use ($data): Requisition {
            $this->requireStatus($requisition, [RequisitionStatus::Pending], 'Only pending requisitions can be declined.');
            $this->requisitions->decline($requisition, $data['reason']);

            return $requisition->refresh();
        });
    }

    /**
     * Cancel your own requisition before it is approved.
     */
    public function cancel(string $id): JsonResponse
    {
        return $this->act($id, 'view', function (Requisition $requisition): Requisition {
            if ((int) $requisition->requestor_id !== (int) auth()->id()) {
                throw new AccessDeniedHttpException('Only the requestor can cancel this requisition.');
            }

            $this->requireStatus($requisition, [RequisitionStatus::Pending, RequisitionStatus::PendingIncharge], 'Only pending requisitions can be cancelled.');
            $this->requisitions->cancel($requisition);

            return $requisition->refresh();
        });
    }

    /**
     * @param  array<string, int>  $quantities
     */
    protected function assertWithinRequested(Requisition $requisition, array $quantities): void
    {
        foreach ($quantities as $itemId => $quantity) {
            $item = $requisition->items->firstWhere('id', $itemId);

            if ($item === null || (int) $quantity > (int) $item->quantity_requested) {
                throw new \DomainException('Approved quantities must match requisition items and not exceed what was requested.');
            }
        }
    }
}
