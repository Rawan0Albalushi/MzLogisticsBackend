<?php

namespace App\Enums;

enum UserType: string
{
    case Platform = 'platform';
    case Customer = 'customer';
    case Provider = 'provider';
    case Driver = 'driver';
}
