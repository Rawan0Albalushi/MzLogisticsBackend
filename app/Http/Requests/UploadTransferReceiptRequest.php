<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class UploadTransferReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $payment = $this->route('payment');

        return $user !== null
            && $user->isCustomer()
            && $payment instanceof Payment
            && $payment->payer_organization_id === $user->organization_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
