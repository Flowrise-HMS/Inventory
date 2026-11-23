<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Pages;

use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores\ExpiringStockTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores\StoreLowStockTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores\StoreStockStatsWidget;
use Modules\Inventory\Models\Store;

/**
 * At-a-glance view for storekeepers: what is low or out, what is expiring,
 * what is waiting at the counter, and any disputed hand-overs.
 */
class StoresDashboard extends Page
{
    use HasFiltersForm;
    use HasPageShield;

    protected static ?string $cluster = InventoryCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?int $navigationSort = -20;

    protected static ?string $navigationLabel = 'Stores dashboard';

    protected static ?string $title = 'Stores dashboard';

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $storeIds = Store::query()->active()->visibleTo($user)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $count = app(StoreStockLevelService::class)->lowStock($storeIds)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Items at or below their reorder point');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('store_id')
                ->label(__('Store'))
                ->placeholder(__('All my stores'))
                ->options(function (): array {
                    $user = Auth::user();

                    return $user instanceof User
                        ? Store::query()->active()->visibleTo($user)->with('branch')->orderBy('name')->get()
                            ->mapWithKeys(fn (Store $store): array => [$store->id => $store->name.' · '.$store->branch?->name])
                            ->all()
                        : [];
                })
                ->searchable(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                StoreStockStatsWidget::class,
                StoreLowStockTableWidget::class,
                ExpiringStockTableWidget::class,
            ])),
        ]);
    }
}
