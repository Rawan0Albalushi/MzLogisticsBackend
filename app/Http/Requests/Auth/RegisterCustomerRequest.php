<?php

namespace App\Http\Requests\Auth;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'company_name' => ['required_if:account_type,company', 'nullable', 'string', 'max:190'],
            'company_name_ar' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
