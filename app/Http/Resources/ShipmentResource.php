<?php

namespace App\Http\Resources;

use App\Enums\QuantityUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'cargo_type' => $this->cargo_type,
            'cargo_description' => $this->cargo_description,
            'weight_tons' => $this->weight_tons,
            'volume_cbm' => $this->volume_cbm,
            'quantity' => $this->quantity,
            'quantity_unit' => QuantityUnit::tryNormalize($this->quantity_unit)?->value ?? $this->quantity_unit,
            'pickup_address' => $this->pickup_address,
            'pickup_city' => $this->pickup_city,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            'delivery_address' => $this->delivery_address,
            'delivery_city' => $this->delivery_city,
            'delivery_lat' => $this->delivery_lat,
            'delivery_lng' => $this->delivery_lng,
            'required_date' => $this->required_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'offer_selection_mode' => $this->offer_selection_mode ?? 'admin',
            'published_at' => $this->published_at,
            'customer' => OrganizationResource::make($this->whenLoaded('customerOrganization')),
            'quotations' => $this->when(
                $this->relationLoaded('quotations') && ! $this->hidesProviderQuotations($request),
                fn () => QuotationResource::collection($this->quotations),
            ),
            'quotations_count' => $this->when(
                isset($this->quotations_count) && ! $this->hidesProviderQuotations($request),
                fn () => $this->quotations_count,
            ),
            'platform_offer' => $this->when(
                $this->relationLoaded('activePlatformOffer')
                    && $this->activePlatformOffer
                    && ! $request->user()?->isProvider()
                    && ! $request->user()?->isDriver(),
                fn () => PlatformOfferResource::make($this->activePlatformOffer),
            ),
            'payment_terms' => $this->paymentTerms()->toArray(),
            'created_at' => $this->created_at,
        ];
    }

    private function hidesProviderQuotations(Request $request): bool
    {
        return (bool) $request->user()?->isCustomer() && $this->usesAdminOfferSelection();
    }
}
