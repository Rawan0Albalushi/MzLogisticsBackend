<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePodDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $file = ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return [
            'invoice' => ['nullable', 'required_without:weight_ticket', ...$file],
            'weight_ticket' => ['nullable', 'required_without:invoice', ...$file],
        ];
    }
}
