<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'shipment_request_id',
    'provider_organization_id',
    'created_by',
    'submitted_on_behalf',
    'total_price',
    'price_per_trip',
    'currency',
    'truck_count',
    'truck_type',
    'truck_capacity_tons',
    'trip_count',
    'quantity_per_trip',
    'duration_days',
    'transport_start_date',
    'additional_costs',
    'conditions',
    'valid_until',
    'status',
])]
class Quotation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'submitted_on_behalf' => 'boolean',
            'total_price' => 'decimal:3',
            'price_per_trip' => 'decimal:3',
            'truck_capacity_tons' => 'decimal:2',
            'quantity_per_trip' => 'decimal:2',
            'transport_start_date' => 'date',
            'additional_costs' => 'decimal:3',
            'valid_until' => 'datetime',
        ];
    }

    public function shipmentRequest(): BelongsTo
    {
        return $this->belongsTo(ShipmentRequest::class);
    }

    public function providerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'provider_organization_id');
    }

    public function scopeFromServiceProviders(Builder $query): Builder
    {
        return $query->whereHas(
            'providerOrganization',
            fn (Builder $organization) => $organization->where('type', OrganizationType::Provider),
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transportJob(): HasOne
    {
        return $this->hasOne(TransportJob::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function dispatchTruckCount(): int
    {
        return max(1, (int) $this->truck_count);
    }

    public function dispatchTripCount(): int
    {
        return max($this->dispatchTruckCount(), max(1, (int) $this->trip_count));
    }

    public function serviceDateForSequence(int $sequence): ?string
    {
        if ($this->transport_start_date === null) {
            return null;
        }

        $offset = intdiv(max(1, $sequence) - 1, $this->dispatchTruckCount());

        return $this->transport_start_date->copy()->addDays($offset)->toDateString();
    }
}
