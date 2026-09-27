<?php

namespace App\Models;

use App\Enums\PlatformOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'shipment_request_id',
    'quotation_id',
    'created_by',
    'provider_price',
    'customer_price',
    'margin_amount',
    'currency',
    'truck_count',
    'truck_type',
    'truck_capacity_tons',
    'trip_count',
    'quantity_per_trip',
    'duration_days',
    'conditions',
    'valid_until',
    'status',
    'published_at',
    'accepted_at',
])]
class PlatformOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PlatformOfferStatus::class,
            'provider_price' => 'decimal:3',
            'customer_price' => 'decimal:3',
            'margin_amount' => 'decimal:3',
            'truck_capacity_tons' => 'decimal:2',
            'quantity_per_trip' => 'decimal:2',
            'valid_until' => 'datetime',
            'published_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function shipmentRequest(): BelongsTo
    {
        return $this->belongsTo(ShipmentRequest::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
