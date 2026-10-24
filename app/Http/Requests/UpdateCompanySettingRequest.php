<?php

namespace App\Http\Requests;

use App\Rules\SafeFavicon;
use App\Support\StoredUpload;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(\App\Services\Access::class)->allows($this->user(), 'settings.edit');
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'pan_number' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable', 'string', 'max:255'],
            'logo' => array_merge(['nullable'], StoredUpload::rules(StoredUpload::IMAGES, 2048)),
            'favicon' => ['nullable', 'file', 'max:512', new SafeFavicon],
        ];
    }
}
