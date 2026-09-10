<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'shipment_request_id' => $this->shipment_request_id,
            'total_price' => $this->total_price,
            'currency' => $this->currency,
            'truck_count' => $this->truck_count,
            'truck_type' => $this->truck_type,
            'truck_capacity_tons' => $this->truck_capacity_tons,
            'trip_count' => $this->trip_count,
            'quantity_per_trip' => $this->quantity_per_trip,
            'duration_days' => $this->duration_days,
            'additional_costs' => $this->additional_costs,
            'conditions' => $this->conditions,
            'valid_until' => $this->valid_until,
            'status' => $this->status,
            'provider' => OrganizationResource::make($this->whenLoaded('providerOrganization')),
            'shipment' => ShipmentResource::make($this->whenLoaded('shipmentRequest')),
            'created_at' => $this->created_at,
        ];
    }
}
