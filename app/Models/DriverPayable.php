<?php

namespace App\Models;

use App\Enums\DriverPayableStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'trip_id',
    'driver_user_id',
    'transport_job_id',
    'amount',
    'currency',
    'status',
    'paid_at',
    'paid_by',
])]
class DriverPayable extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'status' => DriverPayableStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function transportJob(): BelongsTo
    {
        return $this->belongsTo(TransportJob::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
