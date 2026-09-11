<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'currency',
    'pending_balance',
    'available_balance',
    'reserved_balance',
    'lifetime_earned',
    'lifetime_withdrawn',
])]
class Wallet extends Model
{
    protected function casts(): array
    {
        return [
            'pending_balance' => 'decimal:3',
            'available_balance' => 'decimal:3',
            'reserved_balance' => 'decimal:3',
            'lifetime_earned' => 'decimal:3',
            'lifetime_withdrawn' => 'decimal:3',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    public function outstandingBalance(): float
    {
        return round(
            (float) $this->pending_balance + (float) $this->available_balance + (float) $this->reserved_balance,
            3
        );
    }
}
