<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference',
    'shipment_request_id',
    'quotation_id',
    'customer_organization_id',
    'provider_organization_id',
    'total_price',
    'total_quantity',
    'delivered_quantity',
    'currency',
    'status',
    'started_at',
    'completed_at',
])]
class TransportJob extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'total_price' => 'decimal:3',
            'total_quantity' => 'decimal:2',
            'delivered_quantity' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    public function providerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'provider_organization_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class)->orderBy('sequence');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function progressPercent(): int
    {
        if ((float) $this->total_quantity <= 0) {
            return 0;
        }

        return (int) min(100, round(((float) $this->delivered_quantity / (float) $this->total_quantity) * 100));
    }
}
