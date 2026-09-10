<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'truck_id' => ['required', 'integer', 'exists:trucks,id'],
            'driver_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
