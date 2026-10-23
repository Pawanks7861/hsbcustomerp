<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseRequestItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        return [
            'request_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'requested_boq_quantity' => ['required', 'numeric', 'gt:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:5000'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.service_code' => ['nullable', 'string', 'max:100'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.estimated_rate' => ['nullable', 'numeric', 'gte:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('requested_boq_quantity')) {
                return;
            }

            $quantity = Money::scale($this->input('requested_boq_quantity'), 3);

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                $validator->errors()->add('requested_boq_quantity', 'Requested BOQ quantity must be greater than 0.');
            }
        });
    }

    public function requestedBoqQuantity(): string
    {
        return Money::scale($this->validated('requested_boq_quantity'), 3);
    }
}
