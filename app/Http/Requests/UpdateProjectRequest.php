<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id;

        return [
            'project_name' => ['required', 'string', 'max:255'],
            'project_code' => ['required', 'string', 'max:50', Rule::unique('projects', 'project_code')->ignore($projectId)],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
