<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTripOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['trailer_plate', 'delivery_note_number', 'operations_notes'] as $field) {
            if (! $this->exists($field) || ! is_string($this->input($field))) {
                continue;
            }

            $trimmed = trim($this->string($field)->toString());
            $merge[$field] = $trimmed === '' ? null : $trimmed;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'trailer_plate' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} \-]{0,31}$/u'],
            'delivery_note_number' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/]{0,39}$/'],
            'operations_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
