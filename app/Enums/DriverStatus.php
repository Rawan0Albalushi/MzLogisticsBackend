<?php

namespace App\Enums;

enum DriverStatus: string
{
    case Available = 'available';
    case OnTrip = 'on_trip';
    case Inactive = 'inactive';
}
