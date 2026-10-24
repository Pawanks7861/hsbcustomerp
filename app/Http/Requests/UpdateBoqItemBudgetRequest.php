<?php

namespace App\Http\Requests;

use App\Models\BoqItemBudget;
use App\Services\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBoqItemBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->allows($this->user(), 'boq.edit');
    }

    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.name' => ['required', 'string', 'max:100'],
            'entries.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $names = collect($this->input('entries', []))->pluck('name');

            foreach (BoqItemBudget::DEFAULTS as $default) {
                if (! $names->contains($default)) {
                    $validator->errors()->add('entries', "The {$default} budget head is required.");
                }
            }
        });
    }
}
