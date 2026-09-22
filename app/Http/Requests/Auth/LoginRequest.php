<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required_without:login', 'nullable', 'string', 'max:190'],
            'login' => ['required_without:email', 'nullable', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }

    public function identifier(): string
    {
        $login = trim((string) $this->input('login', ''));
        if ($login !== '') {
            return $login;
        }

        return trim((string) $this->input('email', ''));
    }
}
