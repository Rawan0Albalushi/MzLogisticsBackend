<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Submitted = 'submitted';
    case Withdrawn = 'withdrawn';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
