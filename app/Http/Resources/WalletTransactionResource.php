<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'amount' => $this->amount,
            'pending_delta' => $this->pending_delta,
            'available_delta' => $this->available_delta,
            'reserved_delta' => $this->reserved_delta,
            'currency' => $this->currency,
            'description' => $this->description,
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'id' => $this->payment->id,
                'reference' => $this->payment->reference,
            ] : null),
            'job' => $this->whenLoaded('transportJob', fn () => $this->transportJob ? [
                'id' => $this->transportJob->id,
                'reference' => $this->transportJob->reference,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
