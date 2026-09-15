<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'commission_amount' => $this->commission_amount,
            'provider_amount' => $this->provider_amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'payment_link' => $this->paymentLink(),
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
