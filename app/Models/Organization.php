<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'type',
    'account_type',
    'name',
    'name_ar',
    'commercial_register',
    'tax_number',
    'email',
    'phone',
    'city',
    'country',
    'address',
    'status',
    'verification_notes',
    'commission_rate',
])]
class Organization extends Model
{
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'account_type' => AccountType::class,
            'status' => OrganizationStatus::class,
            'commission_rate' => 'decimal:4',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function trucks(): HasMany
    {
        return $this->hasMany(Truck::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function driverProfiles(): HasMany
    {
        return $this->hasMany(DriverProfile::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function shipmentRequests(): HasMany
    {
        return $this->hasMany(ShipmentRequest::class, 'customer_organization_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'provider_organization_id');
    }

    public function customerJobs(): HasMany
    {
        return $this->hasMany(TransportJob::class, 'customer_organization_id');
    }

    public function providerJobs(): HasMany
    {
        return $this->hasMany(TransportJob::class, 'provider_organization_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function isProvider(): bool
    {
        return $this->type === OrganizationType::Provider;
    }

    public function isCustomer(): bool
    {
        return $this->type === OrganizationType::Customer;
    }

    public function commissionRate(): float
    {
        return (float) ($this->commission_rate ?? config('mz.commission_rate'));
    }
}
