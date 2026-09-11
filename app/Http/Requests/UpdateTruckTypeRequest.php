<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTruckTypeRequest extends FormRequest
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
        $type = $this->route('truck_type');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('truck_types', 'code')->ignore($type),
            ],
            'name' => ['sometimes', 'string', 'max:80'],
            'name_ar' => ['sometimes', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
