<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\AutoReorderService;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\PurchaseOrderResource;
use Modules\Inventory\Models\Supplier;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->generateReorderAction(),
            CreateAction::make(),
        ];
    }

    protected function generateReorderAction(): Action
    {
        return Action::make('generate_reorder')
            ->label(__('Generate from low stock'))
            ->icon('heroicon-m-arrow-path-rounded-square')
            ->color('warning')
            ->fillForm(function (): array {
                $branchId = $this->resolveBranchId();
                $suggestions = app(AutoReorderService::class)->suggestions($branchId);

                return [
                    'branch_id' => $branchId,
                    'items' => collect($suggestions)->map(fn (array $row): array => [
                        'selected' => true,
                        'inventory_item_id' => $row['inventory_item_id'],
                        'item_label' => $row['item_name'].' ('.$row['branch_name'].')',
                        'quantity_ordered' => $row['quantity_to_order'],
                    ])->all(),
                ];
            })
            ->form([
                Select::make('branch_id')
                    ->label(__('Branch'))
                    ->options(fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (callable $set, ?string $state): void {
                        $suggestions = app(AutoReorderService::class)->suggestions($state);

                        $set('items', collect($suggestions)->map(fn (array $row): array => [
                            'selected' => true,
                            'inventory_item_id' => $row['inventory_item_id'],
                            'item_label' => $row['item_name'].' ('.$row['branch_name'].')',
                            'quantity_ordered' => $row['quantity_to_order'],
                        ])->all());
                    }),
                Select::make('supplier_id')
                    ->label(__('Supplier'))
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                Repeater::make('items')
                    ->label(__('Suggested lines'))
                    ->schema([
                        Checkbox::make('selected')
                            ->label(__('Include'))
                            ->default(true),
                        TextInput::make('inventory_item_id')->hidden(),
                        TextInput::make('item_label')
                            ->label(__('Item'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('quantity_ordered')
                            ->label(__('Quantity to order'))
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->defaultItems(0),
            ])
            ->action(function (array $data): void {
                $lines = collect($data['items'] ?? [])
                    ->filter(fn (array $item): bool => filter_var($item['selected'] ?? false, FILTER_VALIDATE_BOOLEAN))
                    ->map(fn (array $item): array => [
                        'inventory_item_id' => $item['inventory_item_id'],
                        'quantity_ordered' => (int) $item['quantity_ordered'],
                    ])
                    ->values()
                    ->all();

                if ($lines === []) {
                    Notification::make()
                        ->warning()
                        ->title(__('Select at least one item to reorder'))
                        ->send();

                    return;
                }

                $purchaseOrder = app(AutoReorderService::class)->createDraftPurchaseOrder(
                    supplierId: (string) $data['supplier_id'],
                    branchId: (string) $data['branch_id'],
                    items: $lines,
                );

                Notification::make()
                    ->success()
                    ->title(__('Draft purchase order created'))
                    ->body($purchaseOrder->po_number)
                    ->send();

                $this->redirect(PurchaseOrderResource::getUrl('edit', ['record' => $purchaseOrder]));
            });
    }

    protected function resolveBranchId(): ?string
    {
        $branchId = Context::get('current_branch_id', Auth::user()?->branch_id);

        return $branchId !== null ? (string) $branchId : null;
    }
}
