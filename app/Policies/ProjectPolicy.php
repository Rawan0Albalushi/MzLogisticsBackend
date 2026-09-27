<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Permissions;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::JOBS_VIEW) && ($user->isPlatform() || $user->isProvider());
    }

    public function view(User $user, Project $project): bool
    {
        if (! $user->can(Permissions::JOBS_VIEW)) {
            return false;
        }

        if ($user->isPlatform()) {
            return true;
        }

        if (! $user->isProvider()) {
            return false;
        }

        return $project->jobs()
            ->where('provider_organization_id', $user->organization_id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->isPlatform() && $user->can(Permissions::JOBS_MANAGE);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isPlatform() && $user->can(Permissions::JOBS_MANAGE);
    }
}
