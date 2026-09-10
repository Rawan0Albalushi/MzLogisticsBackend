<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Customer = 'customer';
    case Provider = 'provider';
    case Commission = 'commission';
}
