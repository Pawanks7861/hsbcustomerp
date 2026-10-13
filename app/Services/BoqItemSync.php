<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoqItemSync
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function sync(Boq $boq, array $rows, ?BoqRevision $revision = null): void
    {
        $revision ??= app(BoqRevisionService::class)->editableRevision($boq) ?? $boq->currentRevision;

        if (! $revision || $revision->boq_id !== $boq->id || ! $revision->isDraft()) {
            throw ValidationException::withMessages([
                'items' => 'This BOQ revision is locked.',
            ]);
        }

        DB::transaction(function () use ($boq, $rows, $revision): void {
            $scope = $boq->items()->where('boq_revision_id', $revision->id);
            $keptIds = collect($rows)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $this->guardProcurement($boq, $keptIds, $revision);

            if ($keptIds === []) {
                (clone $scope)->update(['parent_id' => null]);
                (clone $scope)->delete();
            } else {
                (clone $scope)->whereIn('id', $keptIds)->update(['parent_id' => null]);
                (clone $scope)->whereNotIn('id', $keptIds)->update(['parent_id' => null]);
                (clone $scope)->whereNotIn('id', $keptIds)->delete();
            }

            $keyToId = [];

            foreach (array_values($rows) as $index => $row) {
                $attributes = [
                    'sort_order' => $index,
                    'item_number' => trim((string) ($row['item_number'] ?? '')),
                    'service_code' => $this->nullableString($row['service_code'] ?? null),
                    'is_section' => (bool) ($row['is_section'] ?? false),
                    'description' => $this->description($row['description'] ?? ''),
                    'unit' => $this->nullableString($row['unit'] ?? null),
                    'quantity' => ! empty($row['is_section']) ? null : Money::scale($row['quantity'] ?? null, 3),
                    'rate' => ! empty($row['is_section']) ? null : Money::scale($row['rate'] ?? null, 2),
                    'parent_id' => null,
                    'boq_revision_id' => $revision->id,
                ];

                if (! empty($row['id'])) {
                    $item = BoqItem::query()
                        ->where('boq_id', $boq->id)
                        ->where('boq_revision_id', $revision->id)
                        ->whereKey((int) $row['id'])
                        ->firstOrFail();
                    $item->update($attributes);
                } else {
                    $item = $boq->items()->create($attributes);
                }

                $keyToId[$row['client_key']] = $item->id;
            }

            foreach ($rows as $row) {
                $parentId = null;

                if (! empty($row['parent_key'])) {
                    $parentId = $keyToId[$row['parent_key']] ?? null;
                }

                BoqItem::query()
                    ->whereKey($keyToId[$row['client_key']])
                    ->update(['parent_id' => $parentId]);
            }
        });
    }

    /**
     * @param  array<int, int>  $keptIds
     */
    private function guardProcurement(Boq $boq, array $keptIds, BoqRevision $revision): void
    {
        $query = $boq->items()->where('boq_revision_id', $revision->id);
        $removedIds = ($keptIds === []
            ? $query
            : (clone $query)->whereNotIn('id', $keptIds)
        )->pluck('id');

        if ($removedIds->isEmpty()) {
            return;
        }

        $blocked = PurchaseRequest::query()->whereIn('boq_item_id', $removedIds)->exists()
            || PurchaseOrder::query()->whereIn('boq_item_id', $removedIds)->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'items' => 'This BOQ item cannot be deleted because procurement records exist.',
            ]);
        }
    }

    private function description(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($text);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
