<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'provider_organization_id',
    'amount',
    'commission_amount',
    'net_amount',
    'currency',
    'status',
    'period_start',
    'period_end',
    'settled_at',
])]
class Settlement extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'amount' => 'decimal:3',
            'commission_amount' => 'decimal:3',
            'net_amount' => 'decimal:3',
            'period_start' => 'date',
            'period_end' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    public function providerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'provider_organization_id');
    }
}
