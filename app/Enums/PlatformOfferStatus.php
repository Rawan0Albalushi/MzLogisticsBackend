<?php

namespace App\Enums;

enum PlatformOfferStatus: string
{
    case Published = 'published';
    case Accepted = 'accepted';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
