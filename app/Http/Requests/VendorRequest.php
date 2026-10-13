<?php

namespace App\Http\Requests;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'opening_advance' => $this->input('opening_advance') === null || $this->input('opening_advance') === '' ? 0 : $this->input('opening_advance'),
            'opening_outstanding' => $this->input('opening_outstanding') === null || $this->input('opening_outstanding') === '' ? 0 : $this->input('opening_outstanding'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'party_type' => ['sometimes', Rule::in([Vendor::VENDOR, Vendor::CONTRACTOR, Vendor::BOTH])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'pan_number' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'opening_advance' => ['nullable', 'numeric', 'gte:0'],
            'opening_outstanding' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
        ];
    }
}
