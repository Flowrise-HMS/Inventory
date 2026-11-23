<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes;

use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages\CreateStockTake;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages\ListStockTakes;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages\ViewStockTake;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\RelationManagers\StockTakeLinesRelationManager;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Schemas\StockTakeForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Schemas\StockTakeInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Tables\StockTakesTable;
use Modules\Inventory\Models\StockTake;
use Modules\Inventory\Models\Store;

class StockTakeResource extends Resource
{
    protected static ?string $model = StockTake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $navigationLabel = 'Stock-takes';

    protected static ?string $modelLabel = 'stock-take';

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('store_id', Store::query()->visibleTo($user)->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return StockTakeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockTakeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockTakesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StockTakeLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTakes::route('/'),
            'create' => CreateStockTake::route('/create'),
            'view' => ViewStockTake::route('/{record}'),
        ];
    }
}
