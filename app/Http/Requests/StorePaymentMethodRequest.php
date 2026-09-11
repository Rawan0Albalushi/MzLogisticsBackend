<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethodProcessor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
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
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_\-]+$/', 'unique:payment_methods,code'],
            'name' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
            'processor' => ['required', Rule::enum(PaymentMethodProcessor::class)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
