<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
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
        $project = $this->route('project');

        return [
            'project_id' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('projects', 'project_id')->ignore($project instanceof Project ? $project->id : null),
            ],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:120'],
        ];
    }
}
