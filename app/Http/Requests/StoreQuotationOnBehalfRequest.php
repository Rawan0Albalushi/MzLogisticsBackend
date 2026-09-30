<?php

namespace App\Http\Requests;

use App\Models\Quotation;

class StoreQuotationOnBehalfRequest extends StoreQuotationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('createOnBehalf', Quotation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'provider_organization_id' => ['required', 'integer', 'exists:organizations,id'],
            ...parent::rules(),
        ];
    }
}
