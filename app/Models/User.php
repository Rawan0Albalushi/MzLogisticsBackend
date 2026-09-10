<?php

namespace App\Models;

use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'phone',
    'locale',
    'user_type',
    'organization_id',
    'is_active',
    'last_login_at',
    'password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'user_type' => UserType::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function assignedTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_user_id');
    }

    public function isPlatform(): bool
    {
        return $this->user_type === UserType::Platform;
    }

    public function isCustomer(): bool
    {
        return $this->user_type === UserType::Customer;
    }

    public function isProvider(): bool
    {
        return $this->user_type === UserType::Provider;
    }

    public function isDriver(): bool
    {
        return $this->user_type === UserType::Driver;
    }
}
