<?php

namespace App\Models;

use App\Enums\TruckStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'organization_id',
    'plate_number',
    'type',
    'capacity_tons',
    'year',
    'make',
    'model',
    'status',
    'assigned_driver_id',
    'insurance_expires_at',
])]
class Truck extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TruckStatus::class,
            'capacity_tons' => 'decimal:2',
            'insurance_expires_at' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isAvailable(): bool
    {
        return $this->status === TruckStatus::Available;
    }
}
