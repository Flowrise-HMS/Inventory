<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Models\StockTake;
use Modules\Inventory\Models\Store;

class StockTakeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('store_id')
                    ->label(__('Store to count'))
                    ->options(function (): array {
                        $user = Auth::user();

                        if (! $user instanceof User) {
                            return [];
                        }

                        $busy = StockTake::query()->open()->pluck('store_id')->all();

                        return Store::query()->active()->visibleTo($user)->with('branch')->orderBy('name')->get()
                            ->reject(fn (Store $store): bool => in_array($store->id, $busy, true))
                            ->mapWithKeys(fn (Store $store): array => [$store->id => $store->name.' · '.$store->branch?->name])
                            ->all();
                    })
                    ->helperText(__('Stores with a count already in progress are not listed.'))
                    ->searchable()
                    ->required(),
                Toggle::make('is_blind')
                    ->label(__('Blind count'))
                    ->helperText(__('Counters do not see the system quantity, so they count what is really there. Approvers still see it.'))
                    ->default(true)
                    ->inline(false),
                Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
