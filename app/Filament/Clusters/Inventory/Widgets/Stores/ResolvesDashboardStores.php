<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Models\Store;

/**
 * Stores a dashboard widget covers: those visible to the user, narrowed to
 * the page's store filter when one is picked.
 */
trait ResolvesDashboardStores
{
    /**
     * @return list<string>
     */
    protected function dashboardStoreIds(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $ids = Store::query()->active()->visibleTo($user)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $picked = $this->pageFilters['store_id'] ?? null;

        if (filled($picked)) {
            return in_array((string) $picked, $ids, true) ? [(string) $picked] : [];
        }

        return $ids;
    }
}
