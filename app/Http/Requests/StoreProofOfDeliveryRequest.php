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
        $platform = $this->user()?->isPlatform() ?? false;

        return [
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'otp' => $platform ? ['nullable', 'string', 'size:6'] : ['required', 'string', 'size:6'],
            'received_quantity' => $platform ? ['nullable', 'numeric', 'min:0.1'] : ['required', 'numeric', 'min:0.1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'signature' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'invoice' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'weight_ticket' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
