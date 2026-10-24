<?php

namespace App\Http\Requests;

use App\Support\VendorRules;
use Illuminate\Foundation\Http\FormRequest;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->isActive() !== true) {
            return false;
        }

        $permission = $this->isMethod('post') ? 'vendors.create' : 'vendors.edit';

        return app(\App\Services\Access::class)->allows($user, $permission);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(VendorRules::normalize($this->all()));
    }

    public function rules(): array
    {
        return VendorRules::rules($this->isMethod('post'));
    }

    public function messages(): array
    {
        return VendorRules::messages();
    }
}
