<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->isActive() !== true) {
            return false;
        }

        $permission = $this->isMethod('put') || $this->isMethod('patch')
            ? 'purchase_orders.edit'
            : 'purchase_orders.create';

        return app(\App\Services\Access::class)->allows($user, $permission);
    }

    public function rules(): array
    {
        return [
            'po_date' => ['required', 'date'],
            'subject' => ['nullable', 'string', 'max:255'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'delivery_date' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'place_of_supply' => ['nullable', 'string', 'max:255'],
            'gst_percentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'cgst_percentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'sgst_percentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'freight' => ['nullable', 'numeric', 'gte:0'],
            'round_off' => ['nullable', 'numeric', 'between:-1000,1000'],
            'items' => ['nullable', 'array'],
            'items.*.rate' => ['nullable', 'numeric', 'gte:0'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:20'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'rate_change_reason' => ['nullable', 'string', 'max:1000'],
            'terms_conditions' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
