<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Inventory\Models\WardItemIssue;

class WardItemIssuePolicy
{
    use HandlesAuthorization;

    public function __construct(
        protected WardAccessService $wardAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny WardItemIssue') || $this->wardAccess->isAnyWardIncharge($user);
    }

    public function view(User $user, WardItemIssue $issue): bool
    {
        return $user->can('View WardItemIssue')
            || $issue->reported_by === $user->id
            || $this->wardAccess->isWardIncharge($user, $issue->location_id);
    }

    public function create(User $user): bool
    {
        return $user->can('Create WardItemIssue') || $this->wardAccess->isAnyWardIncharge($user);
    }

    public function update(User $user, WardItemIssue $issue): bool
    {
        return $user->can('Update WardItemIssue')
            || $this->wardAccess->isWardIncharge($user, $issue->location_id);
    }

    public function delete(User $user, WardItemIssue $issue): bool
    {
        return $user->can('Delete WardItemIssue');
    }
}
