<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Resources\FieldListTransformer;
use Modules\Inventory\Classes\Services\InterBranchTransferService;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\StockTransferItem;

/**
 * @group Inventory
 *
 * Inter-branch stock transfers. Visible to the sending and the receiving
 * branch; the sender ships, the receiver receives.
 */
class StockTransferController extends InventoryApiController
{
    protected string $branchScope = 'none';

    protected array $with = ['items'];

    public function __construct(protected InterBranchTransferService $transfers) {}

    public static function model(): string
    {
        return StockTransfer::class;
    }

    public function fields(): array
    {
        return ['id', 'transfer_number', 'from_branch_id', 'to_branch_id', 'status', 'shipped_by', 'shipped_at', 'received_by',
            'received_at', 'closed_reason', 'notes', 'created_at'];
    }

    public function filters(): array
    {
        return ['status' => 'status', 'from_branch_id' => 'from_branch_id', 'to_branch_id' => 'to_branch_id'];
    }

    public function storeRules(): array
    {
        return [
            'to_branch_id' => ['required', 'uuid', 'exists:branches,id'],
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

    protected function scopedQuery(): Builder
    {
        $branchId = $this->currentBranchId();

        return StockTransfer::query()->where(fn (Builder $query) => $query->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId));
    }

    protected function createRecord(array $data): Model
    {
        if ($data['to_branch_id'] === $this->currentBranchId()) {
            throw new \InvalidArgumentException('A transfer must go to another branch.');
        }

        return $this->domain(fn () => $this->transfers->create([...$data, 'from_branch_id' => $this->currentBranchId()]));
    }

    protected function present(Model $record, Request $request): array
    {
        return [
            ...parent::present($record, $request),
            'items' => $record->items->map(fn (StockTransferItem $item): array => FieldListTransformer::using($item, [
                'id', 'inventory_item_id', 'quantity_requested', 'quantity_shipped', 'quantity_received',
            ])->toArray($request))->values()->all(),
        ];
    }

    /**
     * Ship from the sending branch.
     *
     * @bodyParam quantities object Map of transfer item id to shipped quantity (defaults to requested). Example: {"9d0c...": 5}
     */
    public function ship(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['quantities' => ['nullable', 'array'], 'quantities.*' => ['integer', 'min:0']]);

        return $this->act($id, 'update', function (StockTransfer $transfer) use ($data): StockTransfer {
            if ($transfer->from_branch_id !== $this->currentBranchId()) {
                throw new \InvalidArgumentException('Only the sending branch can ship a transfer.');
            }
            $this->requireStatus($transfer, [StockTransferStatus::Draft], 'Only draft transfers can be shipped.');
            $this->domain(fn () => $this->transfers->ship($transfer, $data['quantities'] ?? []));

            return $transfer->refresh();
        });
    }

    /**
     * Receive at the destination branch.
     *
     * @bodyParam quantities object Map of transfer item id to received quantity (defaults to shipped). Example: {"9d0c...": 5}
     */
    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['quantities' => ['nullable', 'array'], 'quantities.*' => ['integer', 'min:0']]);

        return $this->act($id, 'update', function (StockTransfer $transfer) use ($data): StockTransfer {
            if ($transfer->to_branch_id !== $this->currentBranchId()) {
                throw new \InvalidArgumentException('Only the receiving branch can receive a transfer.');
            }
            $this->requireStatus($transfer, [StockTransferStatus::Shipped, StockTransferStatus::PartiallyReceived], 'Only shipped transfers can be received.');
            $this->domain(fn () => $this->transfers->receive($transfer, $data['quantities'] ?? []));

            return $transfer->refresh();
        });
    }

    /**
     * Cancel a transfer that has not shipped.
     */
    public function cancel(string $id): JsonResponse
    {
        return $this->act($id, 'update', function (StockTransfer $transfer): StockTransfer {
            $this->requireStatus($transfer, [StockTransferStatus::Draft], 'Only draft transfers can be cancelled.');
            $this->domain(fn () => $this->transfers->cancel($transfer));

            return $transfer->refresh();
        });
    }
}
