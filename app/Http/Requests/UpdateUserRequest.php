<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->allows($this->user(), 'users.edit');
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'designation' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'is_admin' => ['required', 'boolean'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'project_access' => ['nullable', Rule::in(['all', 'selected'])],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }
}
