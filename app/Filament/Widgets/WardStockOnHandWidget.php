<?php

namespace Modules\Inventory\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Models\StockBalance;

/**
 * Displays per-ward inventory balances on Ward Board (injected via PageWidgetsRegistry).
 */
class WardStockOnHandWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Ward Inventory & Stock On-Hand';

    #[Reactive]
    public ?string $wardId = null;

    #[Reactive]
    public ?string $branchId = null;

    protected function getTableQuery(): Builder
    {
        return StockBalance::query()
            ->with(['inventoryItem', 'unit', 'department'])
            ->where('location_type', StockLocationType::Ward->value)
            ->when(
                filled($this->wardId),
                fn (Builder $q) => $q->where('location_id', $this->wardId),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            )
            ->orderBy('quantity_on_hand');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('inventoryItem.name')
                ->label(__('Item'))
                ->description(fn (StockBalance $record): ?string => $record->inventoryItem?->sku)
                ->searchable()
                ->weight('medium'),
            TextColumn::make('inventoryItem.category')
                ->label(__('Category'))
                ->badge(),
            TextColumn::make('lot_number')
                ->label(__('Lot #'))
                ->placeholder('—'),
            TextColumn::make('expiry_date')
                ->label(__('Expiry'))
                ->date('d M Y')
                ->placeholder('—')
                ->color(fn (StockBalance $record): string => ($record->expiry_date && $record->expiry_date->isPast()) ? 'danger' : 'gray'),
            TextColumn::make('quantity_on_hand')
                ->label(__('On Hand'))
                ->numeric()
                ->badge()
                ->color(fn (StockBalance $record): string => ($record->reorder_point !== null && $record->quantity_on_hand <= $record->reorder_point)
                    ? 'danger'
                    : ($record->quantity_on_hand === 0 ? 'warning' : 'success')),
            TextColumn::make('reorder_point')
                ->label(__('Reorder Pt'))
                ->numeric()
                ->placeholder('—'),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('restock')
                ->label(__('Request Restock'))
                ->icon('heroicon-m-shopping-cart')
                ->color('primary')
                ->modalWidth('md')
                ->schema(fn (StockBalance $record): array => [
                    Select::make('department_id')
                        ->label(__('Department'))
                        ->options(fn (): array => $this->branchId
                            ? Department::byBranch($this->branchId)->active()->pluck('name', 'id')->all()
                            : [])
                        ->default($record->department_id)
                        ->required(),
                    TextInput::make('quantity_requested')
                        ->label(__('Quantity to request'))
                        ->numeric()
                        ->minValue(1)
                        ->default(max(1, ($record->reorder_point ?? 10) * 2 - $record->quantity_on_hand))
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('Notes'))
                        ->default(sprintf('Restock request from ward for %s', $record->inventoryItem?->name ?? 'item'))
                        ->rows(2),
                ])
                ->action(function (StockBalance $record, array $data): void {
                    $ward = filled($this->wardId)
                        ? Location::withoutGlobalScope('branch')->find($this->wardId)
                        : null;

                    app(RequisitionService::class)->create([
                        'branch_id' => $ward?->branch_id ?? $this->branchId ?? $record->branch_id,
                        'department_id' => $data['department_id'],
                        'location_id' => $this->wardId,
                        'notes' => $data['notes'] ?? null,
                        'items' => [
                            [
                                'inventory_item_id' => $record->inventory_item_id,
                                'quantity_requested' => (int) $data['quantity_requested'],
                            ],
                        ],
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('Ward restock requisition submitted'))
                        ->send();
                }),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return __('No stock currently recorded for this ward');
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        return __('Items issued to this ward via requisitions will appear here.');
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->can('ViewAny StockBalance')
            || app(WardAccessService::class)->isAnyWardIncharge($user);
    }
}
