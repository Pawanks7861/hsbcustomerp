<?php

namespace App\Http\Requests;

use App\Support\VendorRules;
use Illuminate\Foundation\Http\FormRequest;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
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
