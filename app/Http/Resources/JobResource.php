<?php

namespace App\Http\Resources;

use App\Enums\InvoiceType;
use App\Enums\TripStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $price = $viewer?->isProvider() && $this->provider_price !== null
            ? $this->provider_price
            : $this->total_price;
        $showDriverPay = (bool) $viewer?->isPlatform()
            && $this->relationLoaded('providerOrganization')
            && $this->providerOrganization?->isPlatform()
            && $this->relationLoaded('trips');
        $driverCost = $showDriverPay ? $this->driverCost() : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'total_price' => $price,
            'provider_price' => $this->when(
                (bool) $viewer?->isPlatform() && $this->provider_price !== null,
                $this->provider_price,
            ),
            'currency' => $this->currency,
            'driver_cost' => $this->when($showDriverPay, $driverCost),
            'net_amount' => $this->when($showDriverPay, round((float) $this->total_price - (float) $driverCost, 3)),
            'platform_statement' => $this->when(
                is_array($this->resource->getAttribute('platform_statement')),
                fn () => $this->resource->getAttribute('platform_statement'),
            ),
            'total_quantity' => $this->total_quantity,
            'delivered_quantity' => $this->delivered_quantity,
            'progress_percent' => $this->progressPercent(),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'project' => $this->when(
                $this->relationLoaded('project'),
                fn () => $this->project ? ProjectResource::make($this->project) : null,
            ),
            'customer' => OrganizationResource::make($this->whenLoaded('customerOrganization')),
            'provider' => OrganizationResource::make($this->whenLoaded('providerOrganization')),
            'shipment' => ShipmentResource::make($this->whenLoaded('shipmentRequest')),
            'quotation' => $this->when(
                $this->relationLoaded('quotation') && ! ($viewer?->isCustomer() && $this->provider_price !== null),
                fn () => QuotationResource::make($this->quotation),
            ),
            'trips' => TripResource::collection($this->whenLoaded('trips')),
            'invoices' => $this->when(
                $this->relationLoaded('invoices'),
                function () use ($viewer) {
                    $invoices = $this->invoices;
                    if ($this->provider_price !== null && $viewer?->isCustomer()) {
                        $invoices = $invoices->where('type', InvoiceType::Customer)->values();
                    }
                    if ($this->provider_price !== null && ($viewer?->isProvider() || $viewer?->isDriver())) {
                        $invoices = $invoices->where('type', InvoiceType::Provider)->values();
                    }

                    return InvoiceResource::collection($invoices);
                },
            ),
            'created_at' => $this->created_at,
        ];
    }

    private function driverCost(): float
    {
        return round((float) $this->trips
            ->reject(fn ($trip) => $trip->status === TripStatus::Cancelled)
            ->sum(fn ($trip) => (float) ($trip->driver_pay_amount ?? 0)), 3);
    }
}
