<?php

namespace App\Http\Requests;

use App\Enums\QuantityUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->filled('quantity_unit')) {
            $merge['quantity_unit'] = QuantityUnit::Tons->value;
        } elseif ($normalized = QuantityUnit::tryNormalize($this->input('quantity_unit'))) {
            $merge['quantity_unit'] = $normalized->value;
        }

        $unit = QuantityUnit::tryNormalize($merge['quantity_unit'] ?? $this->input('quantity_unit'));
        if ($unit === QuantityUnit::Tons && ! $this->filled('quantity') && $this->filled('weight_tons')) {
            $merge['quantity'] = $this->input('weight_tons');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'cargo_type' => ['required', 'string', 'max:120'],
            'cargo_description' => ['nullable', 'string', 'max:2000'],
            'weight_tons' => ['required', 'numeric', 'min:0.1'],
            'volume_cbm' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'numeric', 'min:0.1'],
            'quantity_unit' => ['required', 'string', Rule::enum(QuantityUnit::class)],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_city' => ['required', 'string', 'max:120'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:pickup_lng'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:pickup_lat'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'delivery_city' => ['required', 'string', 'max:120'],
            'delivery_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:delivery_lng'],
            'delivery_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:delivery_lat'],
            'required_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }
}
