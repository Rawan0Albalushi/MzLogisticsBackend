<?php

namespace App\Policies;

use App\Models\TransportJob;
use App\Models\User;
use App\Support\Permissions;

class TransportJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::JOBS_VIEW) || $user->isDriver();
    }

    public function view(User $user, TransportJob $job): bool
    {
        if ($user->isPlatform()) {
            return $user->can(Permissions::JOBS_VIEW);
        }

        if ($user->isCustomer()) {
            return $job->customer_organization_id === $user->organization_id;
        }

        if ($user->isProvider()) {
            return $job->provider_organization_id === $user->organization_id;
        }

        return $job->trips()->where('driver_user_id', $user->id)->exists();
    }
}
