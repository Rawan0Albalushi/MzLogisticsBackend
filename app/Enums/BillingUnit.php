<?php

namespace App\Enums;

enum BillingUnit: string
{
    case Job = 'job';
    case Trip = 'trip';

    public function isPerTrip(): bool
    {
        return $this === self::Trip;
    }
}
