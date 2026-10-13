<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && (
            app(\App\Services\Access::class)->allows($user, 'purchase_requests.reject')
            || app(\App\Services\Access::class)->allows($user, 'purchase_orders.reject')
        );
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
