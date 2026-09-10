<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use App\Support\ReferenceGenerator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{user: User, token: string}
     */
    public function registerCustomer(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $accountType = AccountType::from($payload['account_type'] ?? AccountType::Individual->value);

            $organization = Organization::query()->create([
                'type' => OrganizationType::Customer,
                'account_type' => $accountType,
                'name' => $payload['company_name'] ?? $payload['name'],
                'name_ar' => $payload['company_name_ar'] ?? null,
                'email' => $payload['email'],
                'phone' => $payload['phone'] ?? null,
                'city' => $payload['city'] ?? null,
                'country' => $payload['country'] ?? 'OM',
                'address' => $payload['address'] ?? null,
                'status' => OrganizationStatus::Active,
            ]);

            $user = User::query()->create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'phone' => $payload['phone'] ?? null,
                'locale' => $payload['locale'] ?? 'ar',
                'user_type' => UserType::Customer,
                'organization_id' => $organization->id,
                'is_active' => true,
                'password' => $payload['password'],
            ]);

            $role = $accountType === AccountType::Company ? 'Company Admin' : 'Company Admin';
            $user->assignRole($role);

            return $this->issueToken($user);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{user: User, token: string}
     */
    public function registerProvider(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $organization = Organization::query()->create([
                'type' => OrganizationType::Provider,
                'account_type' => AccountType::Company,
                'name' => $payload['company_name'],
                'name_ar' => $payload['company_name_ar'] ?? null,
                'commercial_register' => $payload['commercial_register'] ?? null,
                'tax_number' => $payload['tax_number'] ?? null,
                'email' => $payload['email'],
                'phone' => $payload['phone'] ?? null,
                'city' => $payload['city'] ?? null,
                'country' => $payload['country'] ?? 'OM',
                'address' => $payload['address'] ?? null,
                'status' => OrganizationStatus::Pending,
            ]);

            $user = User::query()->create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'phone' => $payload['phone'] ?? null,
                'locale' => $payload['locale'] ?? 'ar',
                'user_type' => UserType::Provider,
                'organization_id' => $organization->id,
                'is_active' => true,
                'password' => $payload['password'],
            ]);

            $user->assignRole('Provider Admin');

            return $this->issueToken($user);
        });
    }

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issueToken($user);
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    public function resetPassword(array $payload): string
    {
        return Password::reset(
            $payload,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );
    }

    /**
     * @return array{user: User, token: string}
     */
    private function issueToken(User $user): array
    {
        $user->tokens()->delete();
        $token = $user->createToken(ReferenceGenerator::tokenName())->plainTextToken;

        return [
            'user' => $user->load(['organization', 'roles', 'permissions', 'driverProfile']),
            'token' => $token,
        ];
    }
}
