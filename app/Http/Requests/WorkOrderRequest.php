<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($line) => is_array($line) && (int) ($line['boq_item_id'] ?? 0) > 0)
            ->values()
            ->all();

        $this->merge([
            'items' => $items,
            'gst_percentage' => $this->input('gst_percentage') === null || $this->input('gst_percentage') === '' ? 0 : $this->input('gst_percentage'),
            'completion_date' => $this->input('completion_date') ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer'],
            'work_order_date' => ['required', 'date'],
            'completion_date' => ['nullable', 'date'],
            'gst_percentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'payment_terms' => ['nullable', 'string', 'max:5000'],
            'terms_conditions' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.rate' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
