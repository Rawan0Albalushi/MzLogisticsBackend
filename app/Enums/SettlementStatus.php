<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
}
