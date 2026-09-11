<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'currency' => $this->currency,
            'pending_balance' => $this->pending_balance,
            'available_balance' => $this->available_balance,
            'reserved_balance' => $this->reserved_balance,
            'lifetime_earned' => $this->lifetime_earned,
            'lifetime_withdrawn' => $this->lifetime_withdrawn,
            'outstanding_balance' => $this->outstandingBalance(),
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'updated_at' => $this->updated_at,
        ];
    }
}
