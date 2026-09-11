<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case JobEarning = 'job_earning';
    case EarningReleased = 'earning_released';
    case PayoutReserved = 'payout_reserved';
    case PayoutCompleted = 'payout_completed';
    case PayoutRejected = 'payout_rejected';
    case Adjustment = 'adjustment';
}
