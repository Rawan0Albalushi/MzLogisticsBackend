<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'customer_organization_id',
    'created_by',
    'cargo_type',
    'cargo_description',
    'weight_tons',
    'volume_cbm',
    'quantity',
    'quantity_unit',
    'pickup_address',
    'pickup_city',
    'pickup_lat',
    'pickup_lng',
    'delivery_address',
    'delivery_city',
    'delivery_lat',
    'delivery_lng',
    'required_date',
    'notes',
    'status',
    'awarded_quotation_id',
    'published_at',
])]
class ShipmentRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'weight_tons' => 'decimal:2',
            'volume_cbm' => 'decimal:2',
            'quantity' => 'decimal:2',
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'delivery_lat' => 'decimal:7',
            'delivery_lng' => 'decimal:7',
            'required_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function awardedQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'awarded_quotation_id');
    }

    public function transportJob(): HasOne
    {
        return $this->hasOne(TransportJob::class);
    }

    public function isPublished(): bool
    {
        return $this->status === ShipmentStatus::Published;
    }
}
