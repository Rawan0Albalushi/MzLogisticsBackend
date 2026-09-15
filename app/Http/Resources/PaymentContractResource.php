<?php

namespace App\Http\Resources;

use App\Support\PaymentTerms;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $terms = PaymentTerms::fromContract($this->resource);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'billing_trigger' => $this->billing_trigger?->value,
            'due_days' => $terms->dueDays,
            'billing_unit' => $terms->billingUnit->value,
            'prepaid' => $terms->isPrepaid(),
            'per_trip' => $terms->isPerTrip(),
            'pending_billing_trigger' => $this->pending_billing_trigger?->value,
            'pending_due_days' => $this->pending_due_days,
            'pending_billing_unit' => $this->pending_billing_unit?->value,
            'pending_status' => $this->pending_status?->value,
            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'updated_at' => $this->updated_at,
        ];
    }
}
