<?php

namespace App\Enums;

enum TruckStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
