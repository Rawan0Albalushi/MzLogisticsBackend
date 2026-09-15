<?php

namespace App\Enums;

enum PaymentContractRequestStatus: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
}
