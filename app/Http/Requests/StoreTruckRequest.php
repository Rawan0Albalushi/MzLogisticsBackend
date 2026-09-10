<?php

namespace App\Http\Requests;

use App\Enums\TruckStatus;
use App\Enums\TruckType;
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
            'type' => ['required', Rule::enum(TruckType::class)],
            'capacity_tons' => ['required', 'numeric', 'min:0.1'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(TruckStatus::class)],
            'assigned_driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'insurance_expires_at' => ['nullable', 'date'],
        ];
    }
}
