<?php

namespace App\Enums;

enum DriverPayableStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
