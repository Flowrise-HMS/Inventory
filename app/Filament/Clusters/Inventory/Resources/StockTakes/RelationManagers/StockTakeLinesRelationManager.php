<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\StockTakeService;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Models\StockTake;
use Modules\Inventory\Models\StockTakeLine;

/**
 * The count sheet: enter physical counts inline. In a blind count, system
 * quantities and differences stay hidden from counters until submission.
 */
class StockTakeLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Count sheet';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $stockTake = $this->stockTake();
        $canCount = $stockTake->isCounting() && (bool) Auth::user()?->can('update', $stockTake);
        // Approvers explain differences on blind counts after submission.
        $canExplain = ($stockTake->isCounting() && (bool) Auth::user()?->can('update', $stockTake))
            || ($stockTake->status === StockTakeStatus::Submitted
                && ((bool) Auth::user()?->can('update', $stockTake) || (bool) Auth::user()?->can('approve', $stockTake)));
        $showSystem = $this->showsSystemQuantities();

        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['inventoryItem.unit', 'countedBy'])
                ->join('inventory_items', 'inventory_items.id', '=', 'stock_take_lines.inventory_item_id')
                ->select('stock_take_lines.*')
                ->orderBy('inventory_items.name')
                ->orderBy('stock_take_lines.expiry_date'))
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('inventoryItem.name')
                    ->label(__('Item'))
                    ->searchable(query: fn ($query, string $search) => $query->where('inventory_items.name', 'like', "%{$search}%"))
                    ->description(fn (StockTakeLine $record): ?string => $record->is_found_item ? __('Found during count') : null)
                    ->wrap(),
                TextColumn::make('lot_number')->label(__('Lot'))->placeholder('—'),
                TextColumn::make('expiry_date')->label(__('Expiry'))->date()->placeholder('—'),
                TextColumn::make('system_quantity')
                    ->label(__('At start'))
                    ->numeric()
                    ->visible($showSystem),
                TextColumn::make('book_quantity')
                    ->label(__('System when counted'))
                    ->numeric()
                    ->placeholder('—')
                    ->visible($showSystem),
                $canCount
                    ? TextInputColumn::make('counted_quantity')
                        ->label(__('Counted'))
                        ->type('number')
                        ->rules(['nullable', 'integer', 'min:0'])
                        ->extraInputAttributes(['min' => 0, 'inputmode' => 'numeric', 'style' => 'max-width: 7rem'])
                        ->updateStateUsing(function ($state, StockTakeLine $record) {
                            $counted = $state === null || $state === '' ? null : (int) $state;
                            app(StockTakeService::class)->recordCount($record, $counted);

                            return $counted;
                        })
                    : TextColumn::make('counted_quantity')->label(__('Counted'))->numeric()->placeholder('—'),
                TextColumn::make('variance')
                    ->label(__('Difference'))
                    ->state(fn (StockTakeLine $record): ?int => $record->variance())
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : ($state > 0 ? '+'.$state : (string) $state))
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null, $state === 0 => 'gray',
                        $state > 0 => 'info',
                        default => 'danger',
                    })
                    ->visible($showSystem),
                $canExplain
                    ? TextInputColumn::make('variance_reason')
                        ->label(__('Reason for difference'))
                        ->rules(['nullable', 'string', 'max:500'])
                        ->updateStateUsing(function ($state, StockTakeLine $record) {
                            app(StockTakeService::class)->recordReason($record, $state);

                            return $state;
                        })
                    : TextColumn::make('variance_reason')->label(__('Reason for difference'))->placeholder('—')->wrap(),
                TextColumn::make('countedBy.name')
                    ->label(__('Counted by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('uncounted')
                    ->label(__('Not counted yet'))
                    ->query(fn ($query) => $query->whereNull('stock_take_lines.counted_quantity')),
                Filter::make('differences')
                    ->label(__('With a difference'))
                    ->visible($showSystem)
                    ->query(fn ($query) => $query->whereNotNull('stock_take_lines.counted_quantity')
                        ->whereColumn('stock_take_lines.counted_quantity', '!=', 'stock_take_lines.book_quantity')),
            ])
            ->emptyStateHeading(__('This store had no stock lines at the snapshot'))
            ->emptyStateDescription(__('Use "Add found item" for anything on the shelf.'));
    }

    protected function stockTake(): StockTake
    {
        /** @var StockTake $record */
        $record = $this->getOwnerRecord();

        return $record;
    }

    protected function showsSystemQuantities(): bool
    {
        return app(StockTakeService::class)->showsSystemQuantities($this->stockTake(), Auth::user());
    }
}
