<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProofOfDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_name' => ['required', 'string', 'max:120'],
            'otp' => ['required', 'string', 'size:6'],
            'received_quantity' => ['required', 'numeric', 'min:0.1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'signature' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
