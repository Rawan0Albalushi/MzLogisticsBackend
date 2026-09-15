<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'total_price' => $this->total_price,
            'currency' => $this->currency,
            'total_quantity' => $this->total_quantity,
            'delivered_quantity' => $this->delivered_quantity,
            'progress_percent' => $this->progressPercent(),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'customer' => OrganizationResource::make($this->whenLoaded('customerOrganization')),
            'provider' => OrganizationResource::make($this->whenLoaded('providerOrganization')),
            'shipment' => ShipmentResource::make($this->whenLoaded('shipmentRequest')),
            'quotation' => QuotationResource::make($this->whenLoaded('quotation')),
            'trips' => TripResource::collection($this->whenLoaded('trips')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'created_at' => $this->created_at,
        ];
    }
}
