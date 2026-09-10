<?php

namespace App\Enums;

enum JobStatus: string
{
    case PendingDispatch = 'pending_dispatch';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
