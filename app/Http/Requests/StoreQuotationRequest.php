<?php

namespace App\Http\Requests;

use App\Enums\TruckType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'total_price' => ['required', 'numeric', 'min:0.001'],
            'currency' => ['nullable', 'string', 'size:3'],
            'truck_count' => ['required', 'integer', 'min:1'],
            'truck_type' => ['required', Rule::enum(TruckType::class)],
            'truck_capacity_tons' => ['required', 'numeric', 'min:0.1'],
            'trip_count' => ['required', 'integer', 'min:1'],
            'quantity_per_trip' => ['required', 'numeric', 'min:0.1'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'additional_costs' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
