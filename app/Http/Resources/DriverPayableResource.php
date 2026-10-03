<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverPayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'has_receipt' => $this->hasReceipt(),
            'driver' => $this->whenLoaded('driver', fn () => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
            ]),
            'trip' => $this->whenLoaded('trip', fn () => [
                'id' => $this->trip->id,
                'reference' => $this->trip->reference,
            ]),
            'job' => $this->whenLoaded('transportJob', fn () => [
                'id' => $this->transportJob->id,
                'reference' => $this->transportJob->reference,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
