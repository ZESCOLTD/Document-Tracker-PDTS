<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ActivityLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {

    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
    }

    public function create(User $user): bool
    {
    }

    public function update(User $user, ActivityLog $activityLog): bool
    {
    }

    public function delete(User $user, ActivityLog $activityLog): bool
    {
    }

    public function restore(User $user, ActivityLog $activityLog): bool
    {
    }

    public function forceDelete(User $user, ActivityLog $activityLog): bool
    {
    }
}
