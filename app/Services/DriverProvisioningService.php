<?php

namespace App\Services;

use App\Contracts\DriverInviteSender;
use App\Enums\DriverStatus;
use App\Enums\UserType;
use App\Models\DriverActivationToken;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DriverProvisioningService
{
    public function __construct(private readonly DriverInviteSender $inviteSender) {}

    /**
     * @param  array{name: string, phone: string, email?: string|null, license_number?: string|null, license_expires_at?: string|null}  $payload
     * @return array{driver: User, invite_url: string, whatsapp_sent: bool}
     */
    public function provision(User $actor, array $payload): array
    {
        $this->assertCanManage($actor);

        $phone = PhoneNumber::normalize($payload['phone'] ?? null);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => ['A valid mobile number is required.'],
            ]);
        }

        $this->assertPhoneAvailable($phone);

        $email = filled($payload['email'] ?? null)
            ? strtolower(trim((string) $payload['email']))
            : $this->technicalEmail($phone);

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $payload, $phone, $email) {
            $driver = User::query()->create([
                'name' => $payload['name'],
                'email' => $email,
                'phone' => $phone,
                'password' => Str::password(32),
                'locale' => 'ar',
                'user_type' => UserType::Driver,
                'organization_id' => $actor->organization_id,
                'is_active' => true,
                'must_set_password' => true,
            ]);
            $driver->assignRole('Driver');
            DriverProfile::query()->create([
                'user_id' => $driver->id,
                'organization_id' => $driver->organization_id,
                'license_number' => $payload['license_number'] ?? null,
                'license_expires_at' => $payload['license_expires_at'] ?? null,
                'status' => DriverStatus::Available,
            ]);

            $invite = $this->issueInvite($driver);
            $sent = $this->inviteSender->send($driver->fresh(), $invite['url']);

            return [
                'driver' => $driver->load('driverProfile'),
                'invite_url' => $invite['url'],
                'whatsapp_sent' => $sent,
            ];
        });
    }

    /**
     * @return array{driver: User, invite_url: string, whatsapp_sent: bool}
     */
    public function resendInvite(User $actor, User $driver): array
    {
        $this->assertCanManage($actor);
        $this->assertSameOrganization($actor, $driver);

        if (! $driver->isDriver()) {
            abort(404);
        }

        if (! $driver->must_set_password) {
            throw ValidationException::withMessages([
                'driver' => ['This driver has already activated their account.'],
            ]);
        }

        $invite = $this->issueInvite($driver);
        $sent = $this->inviteSender->send($driver, $invite['url']);

        return [
            'driver' => $driver->load('driverProfile'),
            'invite_url' => $invite['url'],
            'whatsapp_sent' => $sent,
        ];
    }

    /**
     * @return array{url: string, token: string}
     */
    public function issueInvite(User $driver): array
    {
        DriverActivationToken::query()
            ->where('user_id', $driver->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plain = Str::random(64);
        DriverActivationToken::query()->create([
            'user_id' => $driver->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays((int) config('mz.driver_activation.expires_days', 7)),
        ]);

        return [
            'token' => $plain,
            'url' => $this->inviteUrl($plain),
        ];
    }

    public function inviteUrl(string $plainToken): string
    {
        $base = rtrim((string) config('mz.driver_activation.invite_base_url'), '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.'token='.urlencode($plainToken);
    }

    public function technicalEmail(string $normalizedPhone): string
    {
        $domain = (string) config('mz.driver_activation.technical_email_domain', 'drivers.mz.local');

        return PhoneNumber::digits($normalizedPhone).'@'.$domain;
    }

    private function assertPhoneAvailable(string $phone): void
    {
        $exists = User::query()
            ->where('user_type', UserType::Driver)
            ->where('phone', $phone)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'phone' => ['A driver with this phone number already exists.'],
            ]);
        }
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->organization_id) {
            abort(403, 'Drivers can only be created by a service provider.');
        }
    }

    private function assertSameOrganization(User $actor, User $driver): void
    {
        if ($actor->isPlatform()) {
            return;
        }

        abort_unless((int) $actor->organization_id === (int) $driver->organization_id, 403);
    }
}
