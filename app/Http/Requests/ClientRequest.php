<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Support\VendorRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        foreach (['name', 'contact_person', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'gst_number', 'pan_number', 'payment_terms', 'notes'] as $field) {
            if (! array_key_exists($field, $input) || ! is_string($input[$field])) {
                continue;
            }
            $value = trim($input[$field]);
            if (in_array($field, ['gst_number', 'pan_number'], true)) {
                $value = strtoupper($value);
            }
            if ($field === 'phone') {
                $value = preg_replace('/\D+/', '', $value) ?? $value;
            }
            $input[$field] = $value === '' ? null : $value;
        }
        $this->merge($input);
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:30', 'regex:'.VendorRules::PHONE],
            'email' => [$required, 'email', 'max:255'],
            'address' => [$required, 'string', 'max:2000'],
            'city' => [$required, 'string', 'max:100'],
            'state' => [$required, 'string', 'max:100'],
            'pincode' => [$required, 'string', 'max:12', 'regex:'.VendorRules::PINCODE],
            'gst_number' => ['nullable', 'string', 'max:20', 'regex:'.VendorRules::GST],
            'pan_number' => ['nullable', 'string', 'max:20', 'regex:'.VendorRules::PAN],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in([Client::ACTIVE, Client::INACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the client name.',
            'contact_person.required' => 'Enter a contact person.',
            'phone.required' => 'Enter a phone number.',
            'phone.regex' => 'Enter a phone number with 10 to 15 digits.',
            'email.required' => 'Enter an email address.',
            'address.required' => 'Enter the address.',
            'city.required' => 'Enter the city.',
            'state.required' => 'Enter the state.',
            'pincode.required' => 'Enter the pincode.',
            'gst_number.regex' => 'Enter a valid 15-character GSTIN.',
            'pan_number.regex' => 'Enter a valid PAN (for example ABCDE1234F).',
        ];
    }
}
