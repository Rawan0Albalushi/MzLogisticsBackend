<?php

namespace App\Policies;

use App\Models\PlatformOffer;
use App\Models\User;
use App\Support\Permissions;

class PlatformOfferPolicy
{
    public function create(User $user): bool
    {
        return $user->isPlatform() && $user->can(Permissions::QUOTATIONS_MANAGE);
    }

    public function withdraw(User $user, PlatformOffer $offer): bool
    {
        return $user->isPlatform() && $user->can(Permissions::QUOTATIONS_MANAGE);
    }

    public function accept(User $user, PlatformOffer $offer): bool
    {
        return $user->isCustomer()
            && $offer->shipmentRequest?->customer_organization_id === $user->organization_id
            && $user->can(Permissions::QUOTATIONS_ACCEPT);
    }
}
