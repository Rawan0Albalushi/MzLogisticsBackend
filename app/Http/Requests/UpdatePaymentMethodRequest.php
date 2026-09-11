<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethodProcessor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $method = $this->route('payment_method');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('payment_methods', 'code')->ignore($method),
            ],
            'name' => ['sometimes', 'string', 'max:80'],
            'name_ar' => ['sometimes', 'string', 'max:80'],
            'processor' => ['sometimes', Rule::enum(PaymentMethodProcessor::class)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
