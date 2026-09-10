<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference',
    'idempotency_key',
    'shipment_request_id',
    'quotation_id',
    'payer_organization_id',
    'amount',
    'commission_amount',
    'provider_amount',
    'currency',
    'method',
    'status',
    'gateway',
    'gateway_reference',
    'paid_at',
    'gateway_payload',
])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:3',
            'commission_amount' => 'decimal:3',
            'provider_amount' => 'decimal:3',
            'paid_at' => 'datetime',
            'gateway_payload' => 'array',
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

    public function payerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'payer_organization_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
