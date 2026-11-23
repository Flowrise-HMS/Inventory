<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Models\Store;

class StoreLowStockTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardStores;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Low and out of stock'))
            ->description(__('Items at or below their reorder point, most urgent first. Suggested order tops up to the max level when one is set.'))
            ->records(fn (): array => app(StoreStockLevelService::class)
                ->lowStock($this->dashboardStoreIds())
                ->keyBy('key')
                ->all())
            ->columns([
                TextColumn::make('item_name')
                    ->label(__('Item'))
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('store_name')
                    ->label(__('Store'))
                    ->description(fn (array $record): string => $record['branch_name']),
                TextColumn::make('quantity_on_hand')
                    ->label(__('On hand'))
                    ->numeric()
                    ->badge()
                    ->color(fn (array $record): string => $record['is_out'] ? 'danger' : 'warning'),
                TextColumn::make('reorder_point')
                    ->label(__('Reorder point'))
                    ->numeric(),
                TextColumn::make('max_level')
                    ->label(__('Max level'))
                    ->placeholder('—'),
                TextColumn::make('quantity_to_order')
                    ->label(__('Suggested order'))
                    ->numeric()
                    ->weight('medium'),
            ])
            ->recordActions([
                Action::make('setLevels')
                    ->label(__('Set levels'))
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->color('gray')
                    ->modalWidth('md')
                    ->modalHeading(fn (array $record): string => __(':item in :store', ['item' => $record['item_name'], 'store' => $record['store_name']]))
                    ->authorize(fn (array $record): bool => ($store = Store::query()->find($record['store_id'])) !== null
                        && (bool) Auth::user()?->can('manageLevels', $store))
                    ->fillForm(fn (array $record): array => [
                        'reorder_point' => $record['reorder_point'],
                        'max_level' => $record['max_level'],
                    ])
                    ->schema([
                        TextInput::make('reorder_point')
                            ->label(__('Reorder point (minimum)'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('max_level')
                            ->label(__('Maximum level (optional)'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->gte('reorder_point')
                            ->nullable(),
                    ])
                    ->action(function (array $record, array $data): void {
                        app(StoreStockLevelService::class)->setLevels(
                            $record['inventory_item_id'],
                            $record['store_id'],
                            (int) $data['reorder_point'],
                            filled($data['max_level'] ?? null) ? (int) $data['max_level'] : null,
                        );

                        Notification::make()->success()->title(__('Stock levels updated'))->send();
                    }),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('Nothing is running low'))
            ->emptyStateDescription(__('Set reorder points on store stock to get alerts here.'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
