<?php

namespace Modules\Inventory\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Models\StockBalance;

/**
 * Reorder point (minimum) and max level for a stock balance. For store stock
 * the levels apply to every lot of the item in that store; ward stock keeps
 * them on the row itself.
 */
class SetReorderPointAction
{
    public static function make(): Action
    {
        return Action::make('setReorderPoint')
            ->label(__('Set stock levels'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->modalHeading(__('Set stock levels'))
            ->modalDescription(fn (StockBalance $record): ?string => static::isStoreStock($record)
                ? __('Applies to :item in :store (all lots).', ['item' => $record->inventoryItem?->name, 'store' => $record->store?->name])
                : null)
            ->modalWidth('md')
            ->authorize(fn (StockBalance $record): bool => static::isStoreStock($record) && $record->store
                ? (bool) Auth::user()?->can('manageLevels', $record->store)
                : (bool) Auth::user()?->can('update', $record))
            ->fillForm(function (StockBalance $record): array {
                if (static::isStoreStock($record)) {
                    $levels = app(StoreStockLevelService::class)->levels((string) $record->inventory_item_id, (string) $record->store_id);

                    return ['reorder_point' => $levels['reorder_point'] ?: null, 'max_level' => $levels['max_level']];
                }

                return ['reorder_point' => $record->reorder_point, 'max_level' => $record->max_level];
            })
            ->schema([
                TextInput::make('reorder_point')
                    ->label(__('Reorder point (minimum)'))
                    ->helperText(__('Low-stock alerts fire at or below this quantity.'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required(),
                TextInput::make('max_level')
                    ->label(__('Maximum level (optional)'))
                    ->helperText(__('Reorder suggestions top stock back up to this quantity.'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->gte('reorder_point')
                    ->nullable(),
            ])
            ->action(function (StockBalance $record, array $data): void {
                $reorderPoint = (int) $data['reorder_point'];
                $maxLevel = filled($data['max_level'] ?? null) ? (int) $data['max_level'] : null;

                if (static::isStoreStock($record)) {
                    app(StoreStockLevelService::class)->setLevels((string) $record->inventory_item_id, (string) $record->store_id, $reorderPoint, $maxLevel);
                } else {
                    $record->update(['reorder_point' => $reorderPoint, 'max_level' => $maxLevel]);
                }

                Notification::make()
                    ->success()
                    ->title(__('Stock levels updated'))
                    ->send();
            });
    }

    protected static function isStoreStock(StockBalance $record): bool
    {
        return $record->location_type === StockLocationType::Dispensary && $record->store_id !== null;
    }
}
