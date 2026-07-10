<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Modules\Core\Classes\Services\BranchService;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Pharmacy\Models\Medication;

class InventoryItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Item details'))
                    ->columns(2)
                    ->schema(self::itemFields()),
                Section::make(__('Initial stock'))
                    ->description(__('Optionally stock the dispensary when creating this item.'))
                    ->visibleOn('create')
                    ->schema(self::initialStockFields()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    public static function itemFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->debounce(200),
            TextInput::make('sku')
                ->maxLength(100)
                ->nullable(),
            Select::make('category')
                ->options(InventoryItemCategory::class)
                ->required(),
            Select::make('medication_id')
                ->label(__('Medication'))
                ->relationship('medication', 'generic_name')
                ->getOptionLabelFromRecordUsing(fn (Medication $record) => $record->displayName())
                ->searchable(['generic_name', 'brand_name'])
                ->preload()
                ->live()
                ->nullable()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $medication = filled($state) ? Medication::query()->find($state) : null;

                    if (! $medication) {
                        return;
                    }

                    $set('name', $medication->displayName());
                    $set('sku', $medication->rxnorm_code ?: ($medication->ndc_code ?: $medication->id));
                }),
            Select::make('unit_id')
                ->relationship('unit', 'label')
                ->preload()
                ->searchable()
                ->required(),
            Toggle::make('is_active')
                ->default(true),
            Textarea::make('description')
                ->nullable()
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function initialStockFields(): array
    {
        return [
            Grid::make(3)
                ->schema([
                    Select::make('stock_branch_id')
                        ->label(__('Branch'))
                        ->options(fn (): array => Branch::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?string => app(BranchService::class)->getDefaultBranchId())
                        ->searchable()
                        ->preload(),
                    TextInput::make('initial_quantity')
                        ->label(__('Initial quantity'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('initial_reorder_point')
                        ->label(__('Reorder point'))
                        ->numeric()
                        ->minValue(0)
                        ->nullable(),
                ]),
        ];
    }

    /**
     * @return list<string>
     */
    public static function stockFieldKeys(): array
    {
        return [
            'stock_branch_id',
            'initial_quantity',
            'initial_reorder_point',
        ];
    }
}
