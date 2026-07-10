<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Classes\Services\StockAdjustmentService;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\StockBalanceResource;
use Modules\Inventory\Models\StockBalance;

class ViewStockBalance extends ViewRecord
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')
                ->label('Adjust stock')
                ->icon('heroicon-m-adjustments-horizontal')
                ->color('warning')
                ->authorize(fn (): bool => auth()->user()->can('adjust', $this->getRecord()))
                ->fillForm(fn (): array => [
                    'new_quantity' => $this->getRecord()->quantity_on_hand,
                ])
                ->form([
                    TextInput::make('new_quantity')
                        ->label('New quantity on hand')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var StockBalance $record */
                    $record = $this->getRecord();

                    app(StockAdjustmentService::class)->adjust(
                        itemId: $record->inventory_item_id,
                        branchId: $record->branch_id,
                        locationType: $record->location_type,
                        departmentId: $record->department_id,
                        newQty: (int) $data['new_quantity'],
                        reason: $data['reason'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Stock adjusted')
                        ->send();

                    $this->record->refresh();
                }),
        ];
    }
}
