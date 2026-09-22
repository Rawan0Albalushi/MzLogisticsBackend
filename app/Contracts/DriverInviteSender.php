<?php

namespace App\Contracts;

use App\Models\User;

interface DriverInviteSender
{
    public function send(User $driver, string $inviteUrl): bool;
}
