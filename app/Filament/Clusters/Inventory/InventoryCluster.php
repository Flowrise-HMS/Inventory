<?php

namespace Modules\Inventory\Filament\Clusters\Inventory;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use Modules\Core\Enums\SidebarGroup;

class InventoryCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = SidebarGroup::Operations;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Inventory';
}
