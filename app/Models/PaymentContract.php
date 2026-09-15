<?php

namespace App\Models;

use App\Enums\BillingTrigger;
use App\Enums\BillingUnit;
use App\Enums\PaymentContractRequestStatus;
use App\Support\PaymentTerms;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'billing_trigger',
    'due_days',
    'billing_unit',
    'pending_billing_trigger',
    'pending_due_days',
    'pending_billing_unit',
    'pending_status',
    'approved_at',
    'approved_by',
    'rejected_at',
    'rejection_reason',
])]
class PaymentContract extends Model
{
    protected function casts(): array
    {
        return [
            'billing_trigger' => BillingTrigger::class,
            'billing_unit' => BillingUnit::class,
            'pending_billing_trigger' => BillingTrigger::class,
            'pending_billing_unit' => BillingUnit::class,
            'pending_status' => PaymentContractRequestStatus::class,
            'due_days' => 'integer',
            'pending_due_days' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function shipmentRequests(): HasMany
    {
        return $this->hasMany(ShipmentRequest::class);
    }

    public function dueDays(): int
    {
        return $this->billing_trigger === BillingTrigger::OnAward ? 0 : (int) $this->due_days;
    }

    public function effectiveTerms(): PaymentTerms
    {
        return PaymentTerms::fromContract($this);
    }

    public function isPrepaid(): bool
    {
        return $this->billing_trigger === BillingTrigger::OnAward;
    }

    public function isDeferred(): bool
    {
        return $this->billing_trigger === BillingTrigger::OnDelivery;
    }

    public function hasPendingRequest(): bool
    {
        return $this->pending_status === PaymentContractRequestStatus::Pending;
    }
}
