<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('project_id')) {
            $this->merge([
                'project_id' => trim((string) $this->input('project_id')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'string', 'max:64', Rule::unique('projects', 'project_id')],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ];
    }
}
