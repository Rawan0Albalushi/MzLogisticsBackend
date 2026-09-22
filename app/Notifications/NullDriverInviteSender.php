<?php

namespace App\Notifications;

use App\Contracts\DriverInviteSender;
use App\Models\User;

class NullDriverInviteSender implements DriverInviteSender
{
    public function send(User $driver, string $inviteUrl): bool
    {
        return false;
    }
}
