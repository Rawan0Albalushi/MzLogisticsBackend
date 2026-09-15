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
            'published_at' => $this->published_at,
            'customer' => OrganizationResource::make($this->whenLoaded('customerOrganization')),
            'quotations' => QuotationResource::collection($this->whenLoaded('quotations')),
            'quotations_count' => $this->whenCounted('quotations'),
            'payment_terms' => $this->paymentTerms()->toArray(),
            'created_at' => $this->created_at,
        ];
    }
}
