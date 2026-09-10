<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'shipment_request_id',
    'provider_organization_id',
    'created_by',
    'total_price',
    'currency',
    'truck_count',
    'truck_type',
    'truck_capacity_tons',
    'trip_count',
    'quantity_per_trip',
    'duration_days',
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
            'truck_type' => \App\Enums\TruckType::class,
            'total_price' => 'decimal:3',
            'truck_capacity_tons' => 'decimal:2',
            'quantity_per_trip' => 'decimal:2',
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
}
