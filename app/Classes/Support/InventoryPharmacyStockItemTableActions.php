<?php

namespace Modules\Inventory\Classes\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Contracts\PharmacyStockItemTableActionsContract;
use Modules\Core\Models\Department;
use Modules\Core\Support\ModuleAvailability;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Models\InventoryItem;
use Modules\Pharmacy\Models\StockItem;

class InventoryPharmacyStockItemTableActions implements PharmacyStockItemTableActionsContract
{
    /**
     * @return array<int, mixed>
     */
    public function recordActions(): array
    {
        if (! ModuleAvailability::inventoryEnabled()) {
            return [];
        }

        return [
            Action::make('request_central_store')
                ->label(__('Request from central store'))
                ->icon('heroicon-m-arrow-path')
                ->color('info')
                ->visible(fn (StockItem $record): bool => Feature::pharmacyProcurementEnabled()
                    && InventoryItem::query()
                        ->where('medication_id', $record->medication_id)
                        ->where('is_active', true)
                        ->exists())
                ->authorize(fn (): bool => Auth::user()?->can('Create Requisition') ?? false)
                ->fillForm(fn (StockItem $record): array => [
                    'quantity_requested' => max(1, (int) $record->reorder_point - (int) $record->quantity_on_hand),
                    'notes' => __('Replenishment request for :medication', [
                        'medication' => $record->medication?->displayName() ?? __('medication'),
                    ]),
                ])
                ->form(fn (StockItem $record): array => [
                    Select::make('department_id')
                        ->label(__('Department'))
                        ->options(fn (): array => Department::byBranch($record->branch_id)->active()->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('quantity_requested')
                        ->label(__('Quantity requested'))
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('Notes'))
                        ->nullable(),
                ])
                ->action(function (StockItem $record, array $data): void {
                    $inventoryItem = InventoryItem::query()
                        ->where('medication_id', $record->medication_id)
                        ->where('is_active', true)
                        ->firstOrFail();

                    app(RequisitionService::class)->create([
                        'branch_id' => $record->branch_id,
                        'department_id' => $data['department_id'],
                        'notes' => $data['notes'] ?? null,
                        'items' => [[
                            'inventory_item_id' => $inventoryItem->id,
                            'quantity_requested' => (int) $data['quantity_requested'],
                        ]],
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('Central store requisition submitted'))
                        ->send();
                }),
        ];
    }
}
