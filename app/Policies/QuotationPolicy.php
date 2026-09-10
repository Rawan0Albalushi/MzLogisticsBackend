<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;
use App\Support\Permissions;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::QUOTATIONS_VIEW);
    }

    public function view(User $user, Quotation $quotation): bool
    {
        if ($user->isPlatform()) {
            return $user->can(Permissions::QUOTATIONS_VIEW);
        }

        if ($user->isProvider()) {
            return $quotation->provider_organization_id === $user->organization_id;
        }

        if ($user->isCustomer()) {
            return $quotation->shipmentRequest?->customer_organization_id === $user->organization_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isProvider() && $user->can(Permissions::QUOTATIONS_CREATE);
    }

    public function withdraw(User $user, Quotation $quotation): bool
    {
        return $user->isProvider()
            && $quotation->provider_organization_id === $user->organization_id
            && $user->can(Permissions::QUOTATIONS_MANAGE);
    }

    public function accept(User $user, Quotation $quotation): bool
    {
        return $user->isCustomer()
            && $quotation->shipmentRequest?->customer_organization_id === $user->organization_id
            && $user->can(Permissions::QUOTATIONS_ACCEPT);
    }
}
