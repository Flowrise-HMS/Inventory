<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny InventoryTransaction');
    }

    public function view(AuthUser $authUser, InventoryTransaction $inventoryTransaction): bool
    {
        return $authUser->can('View InventoryTransaction');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create InventoryTransaction');
    }

    public function update(AuthUser $authUser, InventoryTransaction $inventoryTransaction): bool
    {
        return $authUser->can('Update InventoryTransaction');
    }

    public function delete(AuthUser $authUser, InventoryTransaction $inventoryTransaction): bool
    {
        return $authUser->can('Delete InventoryTransaction');
    }

    public function restore(AuthUser $authUser, InventoryTransaction $inventoryTransaction): bool
    {
        return $authUser->can('Restore InventoryTransaction');
    }

    public function forceDelete(AuthUser $authUser, InventoryTransaction $inventoryTransaction): bool
    {
        return $authUser->can('ForceDelete InventoryTransaction');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny InventoryTransaction');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny InventoryTransaction');
    }

    public function replicate(AuthUser $authUser, InventoryTransaction $inventoryTransaction): bool
    {
        return $authUser->can('Replicate InventoryTransaction');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder InventoryTransaction');
    }
}
