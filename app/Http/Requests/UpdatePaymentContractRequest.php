<?php

namespace App\Http\Requests;

use App\Enums\BillingTrigger;
use App\Enums\BillingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentContractRequest extends FormRequest
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
            'billing_trigger' => ['required', Rule::enum(BillingTrigger::class)],
            'billing_unit' => ['nullable', Rule::enum(BillingUnit::class)],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:'.config('mz.payment_due_days_max', 730)],
        ];
    }
}
