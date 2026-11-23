<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Modules\Inventory\Models\StockTake;
use Modules\Inventory\Models\Store;

/**
 * Stock-takes are scoped to stores the user works with (kept by them, unkept,
 * or every store with `manage_all_stores`). Approving the variances needs
 * `approve_stock_take`.
 */
class StockTakePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny StockTake');
    }

    public function view(AuthUser $authUser, StockTake $stockTake): bool
    {
        return $authUser->can('View StockTake') && $this->worksWithStore($authUser, $stockTake);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create StockTake');
    }

    /** Enter counts, add found items, submit. */
    public function update(AuthUser $authUser, StockTake $stockTake): bool
    {
        return $authUser->can('Update StockTake') && $this->worksWithStore($authUser, $stockTake);
    }

    public function approve(AuthUser $authUser, StockTake $stockTake): bool
    {
        return $authUser->can('approve_stock_take') && $this->worksWithStore($authUser, $stockTake);
    }

    public function delete(AuthUser $authUser, StockTake $stockTake): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    protected function worksWithStore(AuthUser $authUser, StockTake $stockTake): bool
    {
        if (! $authUser instanceof \App\Models\User) {
            return false;
        }

        return Store::query()->whereKey($stockTake->store_id)->visibleTo($authUser)->exists();
    }
}
