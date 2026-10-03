<?php

namespace App\Http\Requests;

use App\Models\ShipmentRequest;
use App\Rules\TransportStartDate;
use App\Rules\UsableTruckType;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'total_price' => ['required_without:price_per_trip', 'numeric', 'min:0.001'],
            'price_per_trip' => ['required_without:total_price', 'numeric', 'min:0.001'],
            'currency' => ['nullable', 'string', 'size:3'],
            'truck_count' => ['required', 'integer', 'min:1'],
            'truck_type' => ['required', 'string', 'max:32', new UsableTruckType($this->user())],
            'truck_capacity_tons' => ['required', 'numeric', 'min:0.1'],
            'trip_count' => ['required', 'integer', 'min:1'],
            'quantity_per_trip' => ['required', 'numeric', 'min:0.1'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'transport_start_date' => ['required', 'date', new TransportStartDate($this->shipment())],
            'additional_costs' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function shipment(): ?ShipmentRequest
    {
        $shipment = $this->route('shipment');

        return $shipment instanceof ShipmentRequest ? $shipment : null;
    }
}
