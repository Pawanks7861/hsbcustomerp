<?php

namespace App\Http\Requests;

use App\Services\Access;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->allows($this->user(), 'boq.create');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'gst_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
