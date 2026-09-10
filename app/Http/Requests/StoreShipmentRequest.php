<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cargo_type' => ['required', 'string', 'max:120'],
            'cargo_description' => ['nullable', 'string', 'max:2000'],
            'weight_tons' => ['required', 'numeric', 'min:0.1'],
            'volume_cbm' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'numeric', 'min:0.1'],
            'quantity_unit' => ['nullable', 'string', 'max:32'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_city' => ['required', 'string', 'max:120'],
            'pickup_lat' => ['nullable', 'numeric'],
            'pickup_lng' => ['nullable', 'numeric'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'delivery_city' => ['required', 'string', 'max:120'],
            'delivery_lat' => ['nullable', 'numeric'],
            'delivery_lng' => ['nullable', 'numeric'],
            'required_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }
}
