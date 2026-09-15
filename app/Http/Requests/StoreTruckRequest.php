<?php

namespace App\Http\Requests;

use App\Enums\TruckStatus;
use App\Rules\UsableTruckType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTruckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(\App\Support\Permissions::FLEET_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'plate_number' => ['required', 'string', 'max:32'],
            'type' => ['required', 'string', 'max:32', new UsableTruckType($this->user(), $this->currentType())],
            'capacity_tons' => ['required', 'numeric', 'min:0.1'],
            'volume_cbm' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'cargo_length_m' => ['nullable', 'numeric', 'min:0.01', 'max:50'],
            'cargo_width_m' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'cargo_height_m' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'axle_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(TruckStatus::class)],
            'assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'insurance_expires_at' => ['nullable', 'date'],
        ];
    }

    private function currentType(): ?string
    {
        $truck = $this->route('truck');
        $type = $truck?->type;

        return is_object($type) && property_exists($type, 'value') ? $type->value : $type;
    }
}
