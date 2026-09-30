<?php

namespace App\Policies;

use App\Enums\TripStatus;
use App\Models\Organization;
use App\Models\Trip;
use App\Models\User;
use App\Support\Permissions;

class TripPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::TRIPS_VIEW) || $user->isDriver();
    }

    public function view(User $user, Trip $trip): bool
    {
        if ($user->isPlatform()) {
            return $user->can(Permissions::TRIPS_VIEW);
        }

        if ($user->isDriver()) {
            return $trip->driver_user_id === $user->id;
        }

        $job = $trip->transportJob;
        if ($user->isProvider()) {
            return $job?->provider_organization_id === $user->organization_id;
        }

        if ($user->isCustomer()) {
            return $job?->customer_organization_id === $user->organization_id;
        }

        return false;
    }

    public function assign(User $user, Trip $trip): bool
    {
        $providerOrganizationId = $trip->transportJob?->provider_organization_id;

        if ($user->isPlatform()) {
            return $user->can(Permissions::TRIPS_ASSIGN)
                && (int) $providerOrganizationId === Organization::platform()->id;
        }

        return $user->isProvider()
            && $providerOrganizationId === $user->organization_id
            && $user->can(Permissions::TRIPS_ASSIGN);
    }

    public function updateOperations(User $user, Trip $trip): bool
    {
        if ($trip->status === TripStatus::Cancelled) {
            return false;
        }

        $canEdit = $user->can(Permissions::TRIPS_UPDATE) || $user->can(Permissions::TRIPS_ASSIGN);
        if (! $canEdit) {
            return false;
        }

        if ($user->isPlatform()) {
            return $this->view($user, $trip);
        }

        return $user->isProvider()
            && $trip->transportJob?->provider_organization_id === $user->organization_id;
    }

    public function updateStatus(User $user, Trip $trip): bool
    {
        if ($user->isDriver()) {
            return $trip->driver_user_id === $user->id;
        }

        return $user->isProvider()
            && $trip->transportJob?->provider_organization_id === $user->organization_id
            && $user->can(Permissions::TRIPS_UPDATE);
    }

    public function submitPod(User $user, Trip $trip): bool
    {
        return $this->updateStatus($user, $trip) || $user->can(Permissions::POD_CREATE);
    }

    public function uploadPodDocuments(User $user, Trip $trip): bool
    {
        return $user->isPlatform()
            && $this->view($user, $trip)
            && $user->can(Permissions::TRIPS_UPDATE);
    }

    public function track(User $user, Trip $trip): bool
    {
        return $this->view($user, $trip);
    }
}
