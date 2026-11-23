<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores;

use App\Models\User;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Enums\ReceiptStatus;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionIssue;

class StoreStockStatsWidget extends BaseWidget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardStores;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '120s';

    protected function getStats(): array
    {
        $storeIds = $this->dashboardStoreIds();
        $levels = app(StoreStockLevelService::class);
        $low = $levels->lowStock($storeIds);
        $expiring = $levels->expiring($storeIds);
        $today = now()->startOfDay();
        $expired = $expiring->filter(fn ($lot): bool => $lot->expiry_date->lt($today))->count();
        $days = $levels->expiryWarningDays();

        $user = Auth::user();
        $waiting = $user instanceof User
            ? Requisition::query()->issuableBy($user)->whereIn('store_id', $storeIds === [] ? ['__none__'] : $storeIds)
            : Requisition::query()->whereRaw('1 = 0');
        $waitingCount = (clone $waiting)->count();
        $backorders = (clone $waiting)->where('status', RequisitionStatus::PartiallyIssued->value)->count();

        $disputed = RequisitionIssue::query()
            ->whereIn('store_id', $storeIds === [] ? ['__none__'] : $storeIds)
            ->where('receipt_status', ReceiptStatus::Disputed->value)
            ->where('receipt_confirmed_at', '>=', now()->subDays(30))
            ->count();

        return [
            Stat::make(__('Out of stock'), $low->where('is_out', true)->count())
                ->description(__('Items with nothing left'))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
            Stat::make(__('Low stock'), $low->where('is_out', false)->count())
                ->description(__('At or below reorder point'))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('warning'),
            Stat::make(__('Expiring soon'), $expiring->count() - $expired)
                ->description(__('Lots expiring within :days days', ['days' => $days]))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make(__('Expired on shelf'), $expired)
                ->description(__('Write off or return these lots'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($expired > 0 ? 'danger' : 'gray'),
            Stat::make(__('Waiting at counter'), $waitingCount)
                ->description(trans_choice(':count is a backorder|:count are backorders', $backorders))
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('info'),
            Stat::make(__('Disputed hand-overs'), $disputed)
                ->description(__('Ward received less (last 30 days)'))
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color($disputed > 0 ? 'danger' : 'gray'),
        ];
    }
}
