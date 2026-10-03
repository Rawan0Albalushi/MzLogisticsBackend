<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $this->loadMissing('quotation.shipmentRequest');
        $adminPriced = (bool) $this->quotation?->shipmentRequest?->usesAdminOfferSelection();
        $hideMarkup = $adminPriced && ($viewer?->isProvider() || $viewer?->isCustomer() || $viewer?->isDriver());

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'invoice_id' => $this->invoice_id,
            'amount' => $hideMarkup && $viewer?->isProvider() ? $this->provider_amount : $this->amount,
            'commission_amount' => $this->when(! $hideMarkup, $this->commission_amount),
            'provider_amount' => $this->when(! $hideMarkup || (bool) $viewer?->isProvider(), $this->provider_amount),
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'payment_link' => $this->paymentLink(),
            'paid_at' => $this->paid_at,
            'has_receipt' => $this->when((bool) $viewer?->isPlatform(), $this->hasReceipt()),
            'transfer_reference' => $this->when(
                (bool) $viewer?->isPlatform() || $viewer?->organization_id === $this->payer_organization_id,
                $this->transfer_reference,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
