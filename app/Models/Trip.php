<?php

namespace App\Models;

use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference',
    'transport_job_id',
    'sequence',
    'truck_id',
    'driver_user_id',
    'assigned_by',
    'planned_quantity',
    'delivered_quantity',
    'status',
    'pickup_address',
    'pickup_city',
    'pickup_lat',
    'pickup_lng',
    'delivery_address',
    'delivery_city',
    'delivery_lat',
    'delivery_lng',
    'current_lat',
    'current_lng',
    'eta_at',
    'otp_code',
    'assigned_at',
    'arrived_pickup_at',
    'loaded_at',
    'in_transit_at',
    'arrived_at',
    'delivered_at',
    'completed_at',
])]
class Trip extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'planned_quantity' => 'decimal:2',
            'delivered_quantity' => 'decimal:2',
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'delivery_lat' => 'decimal:7',
            'delivery_lng' => 'decimal:7',
            'current_lat' => 'decimal:7',
            'current_lng' => 'decimal:7',
            'eta_at' => 'datetime',
            'assigned_at' => 'datetime',
            'arrived_pickup_at' => 'datetime',
            'loaded_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'arrived_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function transportJob(): BelongsTo
    {
        return $this->belongsTo(TransportJob::class);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class);
    }

    public function proofOfDelivery(): HasOne
    {
        return $this->hasOne(ProofOfDelivery::class);
    }
}
