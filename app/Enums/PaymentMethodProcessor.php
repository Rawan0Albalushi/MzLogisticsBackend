<?php

namespace App\Enums;

enum PaymentMethodProcessor: string
{
    case Thawani = 'thawani';
    case Cash = 'cash';
}
