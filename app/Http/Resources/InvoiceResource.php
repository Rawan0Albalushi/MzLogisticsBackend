<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at,
            'payable' => $this->isPayable(),
            'trip_id' => $this->trip_id,
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'job' => JobResource::make($this->whenLoaded('transportJob')),
            'trip' => TripResource::make($this->whenLoaded('trip')),
            'payment' => PaymentResource::make($this->whenLoaded('payment')),
        ];
    }
}
