<?php

namespace App\Notifications;

use App\Contracts\DriverInviteSender;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppDriverInviteSender implements DriverInviteSender
{
    public function send(User $driver, string $inviteUrl): bool
    {
        if (! filter_var(config('mz.whatsapp.driver_invite_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $url = trim((string) config('mz.whatsapp.api_url'));
        $token = trim((string) config('mz.whatsapp.api_token'));
        if ($url === '' || $token === '' || blank($driver->phone)) {
            return false;
        }

        $message = 'تم إنشاء حسابك في MoveX على هذا الرقم. افتح الرابط لتعيين كلمة المرور: '.$inviteUrl;

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post($url, [
                    'to' => PhoneNumber::digits((string) $driver->phone),
                    'from' => config('mz.whatsapp.from'),
                    'message' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Driver WhatsApp invite failed.', [
                'driver_id' => $driver->id,
                'status' => $response->status(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Driver WhatsApp invite exception.', [
                'driver_id' => $driver->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return false;
    }
}
