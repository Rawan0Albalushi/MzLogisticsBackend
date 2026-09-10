<?php

namespace App\Models;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'organization_id',
    'license_number',
    'license_expires_at',
    'status',
])]
class DriverProfile extends Model
{
    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'status' => DriverStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
