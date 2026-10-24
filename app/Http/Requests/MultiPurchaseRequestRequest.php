<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MultiPurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->isActive() !== true) {
            return false;
        }

        $permission = $this->isMethod('put') || $this->isMethod('patch')
            ? 'purchase_requests.edit'
            : 'purchase_requests.create';

        return app(\App\Services\Access::class)->allows($user, $permission);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:5000'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.service_code' => ['nullable', 'string', 'max:100'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seen = [];

            foreach (array_values($this->input('items', [])) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $tag = $row['boq_item_id'] ?? null;

                if ($tag === null || $tag === '') {
                    continue;
                }

                $id = (int) $tag;

                if (isset($seen[$id])) {
                    $validator->errors()->add("items.{$seen[$id]}.boq_item_id", 'Each BOQ item can only be tagged once on a purchase request.');
                    $validator->errors()->add("items.$index.boq_item_id", 'Each BOQ item can only be tagged once on a purchase request.');
                    continue;
                }

                $seen[$id] = $index;
            }
        });
    }
}
