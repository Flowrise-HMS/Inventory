<?php

namespace Modules\Inventory\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Models\StockBalance;

/**
 * Reorder points live on the stock balance (per branch and location), so this
 * is the only place they can be changed once an item exists.
 */
class SetReorderPointAction
{
    public static function make(): Action
    {
        return Action::make('setReorderPoint')
            ->label(__('Set reorder point'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->modalHeading(__('Set reorder point'))
            ->modalWidth('sm')
            ->authorize(fn (StockBalance $record): bool => (bool) Auth::user()?->can('update', $record))
            ->fillForm(fn (StockBalance $record): array => ['reorder_point' => $record->reorder_point])
            ->schema([
                TextInput::make('reorder_point')
                    ->label(__('Reorder point'))
                    ->helperText(__('Low-stock alerts and purchase-order suggestions trigger at or below this quantity.'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required(),
            ])
            ->action(function (StockBalance $record, array $data): void {
                $record->update(['reorder_point' => (int) $data['reorder_point']]);

                Notification::make()
                    ->success()
                    ->title(__('Reorder point updated'))
                    ->send();
            });
    }
}
