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
     * @param  array{name: string, phone: string, email?: string|null, license_number?: string|null, license_expires_at?: string|null, civil_id?: string|null, trip_rate?: float|int|string|null}  $payload
     * @return array{driver: User, activation_code: string, whatsapp_sent: bool}
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

        $organizationId = $this->managedOrganizationId($actor);
        $tripRate = $actor->isPlatform() ? $this->tripRate($payload) : null;

        return DB::transaction(function () use ($payload, $phone, $email, $organizationId, $tripRate) {
            $driver = User::query()->create([
                'name' => $payload['name'],
                'email' => $email,
                'phone' => $phone,
                'password' => Str::password(32),
                'locale' => 'ar',
                'user_type' => UserType::Driver,
                'organization_id' => $organizationId,
                'is_active' => true,
                'must_set_password' => true,
            ]);
            $driver->assignRole('Driver');
            DriverProfile::query()->create([
                'user_id' => $driver->id,
                'organization_id' => $driver->organization_id,
                'license_number' => $payload['license_number'] ?? null,
                'license_expires_at' => $payload['license_expires_at'] ?? null,
                'civil_id' => $this->normalizeCivilId($payload['civil_id'] ?? null),
                'trip_rate' => $tripRate,
                'status' => DriverStatus::Available,
            ]);

            $invite = $this->issueInvite($driver);
            $sent = $this->inviteSender->send($driver->fresh(), $invite['code']);

            return [
                'driver' => $driver->load('driverProfile'),
                'activation_code' => $invite['code'],
                'whatsapp_sent' => $sent,
            ];
        });
    }

    /**
     * @param  array{name: string, phone: string, email?: string|null, license_number?: string|null, license_expires_at?: string|null, civil_id?: string|null, trip_rate?: float|int|string|null, status?: string|null}  $payload
     */
    public function update(User $actor, User $driver, array $payload): User
    {
        $this->assertCanManage($actor);
        $this->assertSameOrganization($actor, $driver);

        if (! $driver->isDriver()) {
            abort(404);
        }

        $phone = PhoneNumber::normalize($payload['phone'] ?? null);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => ['A valid mobile number is required.'],
            ]);
        }

        $this->assertPhoneAvailable($phone, $driver->id);

        $emailInput = trim((string) ($payload['email'] ?? ''));
        $email = $emailInput !== ''
            ? strtolower($emailInput)
            : $this->technicalEmail($phone);

        if (User::query()->where('email', $email)->whereKeyNot($driver->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $driver, $payload, $phone, $email) {
            $phoneChanged = $driver->phone !== $phone;
            $driver->fill([
                'name' => $payload['name'],
                'email' => $email,
                'phone' => $phone,
            ])->save();

            if ($phoneChanged && $driver->must_set_password) {
                DriverActivationToken::query()
                    ->where('user_id', $driver->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);
            }

            $profile = $driver->driverProfile ?? new DriverProfile([
                'user_id' => $driver->id,
                'organization_id' => $driver->organization_id,
                'status' => DriverStatus::Available,
            ]);
            $profileData = [
                'license_number' => filled($payload['license_number'] ?? null) ? $payload['license_number'] : null,
                'license_expires_at' => filled($payload['license_expires_at'] ?? null) ? $payload['license_expires_at'] : null,
                'status' => $payload['status'] ?? $profile->status ?? DriverStatus::Available,
            ];
            if (array_key_exists('civil_id', $payload)) {
                $profileData['civil_id'] = $this->normalizeCivilId($payload['civil_id'], $profile->id);
            }
            if ($actor->isPlatform() && array_key_exists('trip_rate', $payload)) {
                $profileData['trip_rate'] = $this->tripRate($payload);
            }
            $profile->fill($profileData)->save();

            return $driver->refresh()->load('driverProfile');
        });
    }

    /**
     * @return array{driver: User, activation_code: string, whatsapp_sent: bool}
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
        $sent = $this->inviteSender->send($driver, $invite['code']);

        return [
            'driver' => $driver->load('driverProfile'),
            'activation_code' => $invite['code'],
            'whatsapp_sent' => $sent,
        ];
    }

    /**
     * @return array{code: string}
     */
    public function issueInvite(User $driver): array
    {
        return DB::transaction(function () use ($driver) {
            DriverActivationToken::query()
                ->where('user_id', $driver->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $phone = (string) $driver->phone;
            $expiresAt = now()->addDays((int) config('mz.driver_activation.expires_days', 7));

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
                $hash = DriverActivationToken::hashFor($phone, $code);
                if (DriverActivationToken::query()->where('token_hash', $hash)->exists()) {
                    continue;
                }

                DriverActivationToken::query()->create([
                    'user_id' => $driver->id,
                    'token_hash' => $hash,
                    'attempts' => 0,
                    'expires_at' => $expiresAt,
                ]);

                return ['code' => $code];
            }

            throw ValidationException::withMessages([
                'driver' => ['Unable to create an activation code. Try again.'],
            ]);
        });
    }

    public function technicalEmail(string $normalizedPhone): string
    {
        $domain = (string) config('mz.driver_activation.technical_email_domain', 'drivers.mz.local');

        return PhoneNumber::digits($normalizedPhone).'@'.$domain;
    }

    private function normalizeCivilId(mixed $value, ?int $ignoreProfileId = null): ?string
    {
        $civilId = preg_replace('/\s+/', '', trim((string) ($value ?? ''))) ?? '';
        if ($civilId === '') {
            return null;
        }

        if (! preg_match('/^\d{5,20}$/', $civilId)) {
            throw ValidationException::withMessages([
                'civil_id' => ['The civil ID must be 5 to 20 digits.'],
            ]);
        }

        $taken = DriverProfile::query()
            ->where('civil_id', $civilId)
            ->when($ignoreProfileId, fn ($query) => $query->whereKeyNot($ignoreProfileId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'civil_id' => ['This civil ID is already registered to another driver.'],
            ]);
        }

        return $civilId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tripRate(array $payload): ?float
    {
        if (! array_key_exists('trip_rate', $payload) || $payload['trip_rate'] === null || $payload['trip_rate'] === '') {
            return null;
        }

        return round((float) $payload['trip_rate'], 3);
    }

    private function assertPhoneAvailable(string $phone, ?int $ignoreUserId = null): void
    {
        $exists = User::query()
            ->where('user_type', UserType::Driver)
            ->where('phone', $phone)
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'phone' => ['A driver with this phone number already exists.'],
            ]);
        }
    }

    private function assertCanManage(User $actor): void
    {
        $this->managedOrganizationId($actor);
    }

    private function managedOrganizationId(User $actor): int
    {
        if ($actor->isPlatform()) {
            return $actor->fleetOrganizationId();
        }

        if (! $actor->organization_id) {
            abort(403, 'Drivers can only be created by a service provider.');
        }

        return (int) $actor->organization_id;
    }

    private function assertSameOrganization(User $actor, User $driver): void
    {
        abort_unless((int) $this->managedOrganizationId($actor) === (int) $driver->organization_id, 403);
    }
}
