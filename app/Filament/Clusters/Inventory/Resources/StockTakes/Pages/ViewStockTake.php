<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\StockTakeService;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\StockTakeResource;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockTake;

/**
 * @property StockTake $record
 */
class ViewStockTake extends ViewRecord
{
    protected static string $resource = StockTakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addFoundItem')
                ->label(__('Add found item'))
                ->icon('heroicon-m-plus')
                ->color('gray')
                ->visible(fn (): bool => $this->record->isCounting())
                ->authorize(fn (): bool => (bool) Auth::user()?->can('update', $this->record))
                ->modalDescription(__('Stock on the shelf that is not on the count sheet.'))
                ->schema([
                    Select::make('inventory_item_id')
                        ->label(__('Item'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => InventoryItem::query()
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                            ->orderBy('name')->limit(25)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => InventoryItem::query()->whereKey($value)->value('name'))
                        ->required(),
                    TextInput::make('counted_quantity')->label(__('Quantity found'))->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('lot_number')->label(__('Lot (optional)'))->maxLength(100),
                    DatePicker::make('expiry_date')->label(__('Expiry (optional)')),
                    Textarea::make('variance_reason')->label(__('Why it was not on the system'))->rows(2)->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $this->run($action, fn () => app(StockTakeService::class)->addFoundItem(
                        $this->record,
                        $data['inventory_item_id'],
                        $data['lot_number'] ?? null,
                        $data['expiry_date'] ?? null,
                        (int) $data['counted_quantity'],
                        $data['variance_reason'] ?? null,
                    ), __('Found item added'));
                }),
            Action::make('submit')
                ->label(__('Submit for approval'))
                ->icon('heroicon-m-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => $this->record->isCounting())
                ->authorize(fn (): bool => (bool) Auth::user()?->can('update', $this->record))
                ->requiresConfirmation()
                ->modalDescription(__('Counts are locked once submitted. A supervisor then approves the differences.'))
                ->action(fn (Action $action) => $this->run($action, fn () => app(StockTakeService::class)->submit($this->record), __('Submitted for approval'))),
            Action::make('approve')
                ->label(__('Approve and post adjustments'))
                ->icon('heroicon-m-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === StockTakeStatus::Submitted)
                ->authorize(fn (): bool => (bool) Auth::user()?->can('approve', $this->record))
                ->requiresConfirmation()
                ->modalDescription(function (): string {
                    $s = app(StockTakeService::class)->summary($this->record);

                    return __(':lines line(s) differ: :over unit(s) over and :short unit(s) short will be posted as stock adjustments.', [
                        'lines' => $s['with_variance'],
                        'over' => $s['units_over'],
                        'short' => $s['units_short'],
                    ]);
                })
                ->action(fn (Action $action) => $this->run($action, fn () => app(StockTakeService::class)->approve($this->record), __('Stock-take approved; adjustments posted'))),
            Action::make('returnForRecount')
                ->label(__('Send back for recount'))
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === StockTakeStatus::Submitted)
                ->authorize(fn (): bool => (bool) Auth::user()?->can('approve', $this->record))
                ->schema([Textarea::make('reason')->label(__('What needs recounting?'))->required()])
                ->action(fn (array $data, Action $action) => $this->run($action, fn () => app(StockTakeService::class)->returnForRecount($this->record, $data['reason']), __('Sent back for recount'))),
            ActionGroup::make([
                Action::make('countSheet')
                    ->label(__('Print count sheet'))
                    ->icon('heroicon-m-printer')
                    ->url(fn (): string => route('inventory.stock-takes.count-sheet', $this->record))
                    ->openUrlInNewTab(),
                Action::make('varianceReport')
                    ->label(__('Print variance report'))
                    ->icon('heroicon-m-document-chart-bar')
                    ->visible(fn (): bool => in_array($this->record->status, [StockTakeStatus::Submitted, StockTakeStatus::Approved], true))
                    ->url(fn (): string => route('inventory.stock-takes.variance-report', $this->record))
                    ->openUrlInNewTab(),
                Action::make('cancel')
                    ->label(__('Cancel stock-take'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => $this->record->status->isOpen())
                    ->authorize(fn (): bool => (bool) Auth::user()?->can('update', $this->record))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn () => app(StockTakeService::class)->cancel($this->record, $data['reason']), __('Stock-take cancelled'))),
            ])->icon('heroicon-m-ellipsis-vertical')->color('gray'),
        ];
    }

    protected function run(Action $action, \Closure $callback, string $success): void
    {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title(__('Could not complete'))->body($e->getMessage())->send();
            $action->halt();

            return;
        }

        $this->record->refresh();
        Notification::make()->success()->title($success)->send();
    }
}
