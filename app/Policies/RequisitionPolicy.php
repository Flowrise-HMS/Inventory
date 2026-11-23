<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Modules\Inventory\Models\Requisition;

class RequisitionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny Requisition');
    }

    public function view(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('View Requisition');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create Requisition');
    }

    public function update(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Update Requisition');
    }

    public function delete(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Delete Requisition');
    }

    public function approve(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Approve Requisition');
    }

    /**
     * Issuing is restricted to the serving store's keepers once a store has
     * keepers assigned. Stores without keepers, and users with
     * `manage_all_stores`, keep the plain permission check.
     */
    public function issue(AuthUser $authUser, Requisition $requisition): bool
    {
        if (! $authUser->can('Issue Requisition')) {
            return false;
        }

        $store = $requisition->store;

        if ($store === null || ! $store->hasKeepers() || $authUser->can('manage_all_stores')) {
            return true;
        }

        return $store->keepers()->whereKey($authUser->getKey())->exists();
    }

    public function restore(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Restore Requisition');
    }

    public function forceDelete(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('ForceDelete Requisition');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny Requisition');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny Requisition');
    }

    public function replicate(AuthUser $authUser, Requisition $requisition): bool
    {
        return $authUser->can('Replicate Requisition');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder Requisition');
    }
}
