<?php

namespace App\Policies;

use App\Enums\ShipmentStatus;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\Permissions;

class ShipmentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::SHIPMENTS_VIEW) || $user->isDriver();
    }

    public function view(User $user, ShipmentRequest $shipment): bool
    {
        if ($user->isPlatform()) {
            return $user->can(Permissions::SHIPMENTS_VIEW);
        }

        if ($user->isCustomer()) {
            return $shipment->customer_organization_id === $user->organization_id;
        }

        if ($user->isProvider()) {
            return $shipment->status === ShipmentStatus::Published
                || $shipment->quotations()->where('provider_organization_id', $user->organization_id)->exists();
        }

        return $shipment->transportJob?->trips()->where('driver_user_id', $user->id)->exists() ?? false;
    }

    public function create(User $user): bool
    {
        if ($user->isPlatform()) {
            return $user->can(Permissions::SHIPMENTS_MANAGE) || $user->can(Permissions::SHIPMENTS_CREATE);
        }

        return $user->isCustomer() && $user->can(Permissions::SHIPMENTS_CREATE);
    }

    public function update(User $user, ShipmentRequest $shipment): bool
    {
        if ($user->isPlatform()) {
            return $user->can(Permissions::SHIPMENTS_MANAGE)
                && $shipment->status === ShipmentStatus::Draft;
        }

        return $this->customerCanRevise($user, $shipment);
    }

    public function publish(User $user, ShipmentRequest $shipment): bool
    {
        return $this->customerCanRevise($user, $shipment);
    }

    public function cancel(User $user, ShipmentRequest $shipment): bool
    {
        return $this->customerCanRevise($user, $shipment)
            || ($user->isPlatform() && $user->can(Permissions::SHIPMENTS_MANAGE));
    }

    private function customerCanRevise(User $user, ShipmentRequest $shipment): bool
    {
        return $user->isCustomer()
            && $shipment->customer_organization_id === $user->organization_id
            && $user->can(Permissions::SHIPMENTS_MANAGE);
    }
}
