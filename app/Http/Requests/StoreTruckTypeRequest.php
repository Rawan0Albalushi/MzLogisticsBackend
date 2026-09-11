<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTruckTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::FLEET_MANAGE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('truck_types', 'code')],
            'name' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
