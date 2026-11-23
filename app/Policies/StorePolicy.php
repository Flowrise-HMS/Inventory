<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Modules\Inventory\Models\Store;

class StorePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny Store');
    }

    public function view(AuthUser $authUser, Store $store): bool
    {
        return $authUser->can('View Store');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create Store');
    }

    public function update(AuthUser $authUser, Store $store): bool
    {
        return $authUser->can('Update Store');
    }

    /**
     * The main store and any store that holds stock or requisitions cannot be
     * deleted (the foreign keys restrict it); deactivate it instead.
     */
    public function delete(AuthUser $authUser, Store $store): bool
    {
        if ($store->is_main || $store->stockBalances()->exists() || $store->requisitions()->exists()) {
            return false;
        }

        return $authUser->can('Delete Store');
    }

    /**
     * Set reorder points and max levels for items in this store: its keepers,
     * stock managers (`Update StockBalance`) and `manage_all_stores` users.
     */
    public function manageLevels(AuthUser $authUser, Store $store): bool
    {
        if ($authUser->can('Update StockBalance') || $authUser->can('manage_all_stores')) {
            return true;
        }

        return $store->keepers()->whereKey($authUser->getKey())->exists();
    }

    public function restore(AuthUser $authUser, Store $store): bool
    {
        return $authUser->can('Restore Store');
    }

    public function forceDelete(AuthUser $authUser, Store $store): bool
    {
        return $authUser->can('ForceDelete Store');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny Store');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny Store');
    }

    public function replicate(AuthUser $authUser, Store $store): bool
    {
        return $authUser->can('Replicate Store');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder Store');
    }
}
