<?php

namespace App\Http\Requests;

use App\Models\BoqItem;
use App\Services\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->allows($this->user(), 'boq.edit');
    }

    public function rules(): array
    {
        return [
            'revision_id' => ['nullable', 'integer'],
            'finalize' => ['nullable', 'boolean'],
            'title' => ['required', 'string', 'max:255'],
            'gst_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'items' => ['present', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.client_key' => ['required', 'string', 'max:64'],
            'items.*.parent_key' => ['nullable', 'string', 'max:64'],
            'items.*.item_number' => ['nullable', 'string', 'max:50'],
            'items.*.service_code' => ['nullable', 'string', 'max:100'],
            'items.*.is_section' => ['nullable', 'boolean'],
            'items.*.description' => ['required', 'string', 'max:20000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            $keys = collect($items)->pluck('client_key')->filter()->all();

            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('items', 'Each BOQ row must have a unique key.');
            }

            $ids = collect($items)->pluck('id')->filter();

            if ($ids->count() !== $ids->unique()->count()) {
                $validator->errors()->add('items', 'Duplicate BOQ items were submitted.');
            }

            $boq = $this->route('boq');

            foreach ($items as $index => $item) {
                $parentKey = $item['parent_key'] ?? null;

                if ($parentKey) {
                    if (! in_array($parentKey, $keys, true)) {
                        $validator->errors()->add("items.$index.parent_key", 'Sub-item parent is invalid.');
                    }

                    if ($parentKey === ($item['client_key'] ?? null)) {
                        $validator->errors()->add("items.$index.parent_key", 'An item cannot be its own parent.');
                    }

                    $parent = collect($items)->firstWhere('client_key', $parentKey);

                    if ($parent && ! empty($parent['parent_key'])) {
                        $validator->errors()->add("items.$index.parent_key", 'Only one sub-item level is supported.');
                    }
                }

                if (! empty($item['id']) && $boq) {
                    $query = BoqItem::query()
                        ->where('boq_id', $boq->id)
                        ->whereKey($item['id']);

                    if (! empty($this->input('revision_id'))) {
                        $query->where('boq_revision_id', (int) $this->input('revision_id'));
                    }

                    if (! $query->exists()) {
                        $validator->errors()->add("items.$index.id", 'This BOQ item does not belong to the selected BOQ revision.');
                    }
                }
            }
        });
    }
}
