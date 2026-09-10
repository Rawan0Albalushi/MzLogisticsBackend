<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'organization_id',
    'transport_job_id',
    'payment_id',
    'type',
    'amount',
    'currency',
    'status',
    'issued_at',
    'due_at',
])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:3',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transportJob(): BelongsTo
    {
        return $this->belongsTo(TransportJob::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
