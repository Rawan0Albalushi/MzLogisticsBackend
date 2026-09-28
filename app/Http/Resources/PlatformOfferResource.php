<?php

namespace App\Http\Resources;

use App\Services\TruckTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $truckType = is_object($this->truck_type) ? $this->truck_type->value : $this->truck_type;
        $payload = [
            'id' => $this->id,
            'reference' => $this->reference,
            'shipment_request_id' => $this->shipment_request_id,
            'customer_price' => $this->customer_price,
            'currency' => $this->currency,
            'truck_count' => $this->truck_count,
            'truck_type' => $truckType,
            'truck_type_label' => app(TruckTypeService::class)->label($truckType),
            'truck_capacity_tons' => $this->truck_capacity_tons,
            'trip_count' => $this->trip_count,
            'quantity_per_trip' => $this->quantity_per_trip,
            'duration_days' => $this->duration_days,
            'conditions' => $this->conditions,
            'valid_until' => $this->valid_until,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'accepted_at' => $this->accepted_at,
        ];

        if ($request->user()?->isPlatform()) {
            $this->resource->loadMissing('quotation.providerOrganization');
            $ownedByPlatform = $this->quotation?->providerOrganization?->isPlatform() === true;
            $payload['quotation_id'] = $this->quotation_id;
            $payload['provider_price'] = $this->provider_price;
            $payload['margin_amount'] = $this->margin_amount;
            $payload['owned_by_platform'] = $ownedByPlatform;
            $payload['additional_costs'] = $this->quotation?->additional_costs;
            if (! $ownedByPlatform && $this->quotation?->providerOrganization) {
                $payload['provider'] = OrganizationResource::make($this->quotation->providerOrganization);
            }
        }

        return $payload;
    }
}
