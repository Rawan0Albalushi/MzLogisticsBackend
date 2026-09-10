<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Awarded = 'awarded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
