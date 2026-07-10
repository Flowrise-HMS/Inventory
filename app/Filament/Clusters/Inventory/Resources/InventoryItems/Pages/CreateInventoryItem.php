<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\InventoryItemResource;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Schemas\InventoryItemForm;
use Modules\Inventory\Models\InventoryItem;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;

    /**
     * @var array<string, mixed>
     */
    private array $stockData = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->stockData = Arr::only($data, InventoryItemForm::stockFieldKeys());

        return Arr::except($data, InventoryItemForm::stockFieldKeys());
    }

    protected function afterCreate(): void
    {
        $branchId = $this->stockData['stock_branch_id'] ?? null;
        $quantity = (int) ($this->stockData['initial_quantity'] ?? 0);
        $reorderPoint = filled($this->stockData['initial_reorder_point'] ?? null)
            ? (int) $this->stockData['initial_reorder_point']
            : null;

        if (blank($branchId) || ($quantity <= 0 && $reorderPoint === null)) {
            return;
        }

        /** @var InventoryItem $record */
        $record = $this->record;

        app(StockLedgerService::class)->addOpeningStock(
            itemId: $record->id,
            branchId: $branchId,
            qty: $quantity,
            reorderPoint: $reorderPoint,
        );
    }
}
