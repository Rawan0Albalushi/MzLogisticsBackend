<?php

namespace App\Enums;

enum OrganizationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
}
