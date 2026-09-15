<?php

namespace App\Enums;

enum BillingTrigger: string
{
    case OnAward = 'on_award';
    case OnDelivery = 'on_delivery';

    public function isPrepaid(): bool
    {
        return $this === self::OnAward;
    }
}
