<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoqRevisionService
{
    public function ensureOriginal(Boq $boq, ?User $user = null): BoqRevision
    {
        $existing = $boq->revisions()->orderBy('revision_number')->first();

        if ($existing) {
            return $existing;
        }

        $revision = $boq->revisions()->create([
            'revision_number' => 0,
            'revision_date' => now()->toDateString(),
            'status' => BoqRevision::DRAFT,
            'created_by' => $user?->id ?? auth()->id(),
        ]);

        $boq->forceFill(['current_revision_id' => $revision->id])->saveQuietly();

        return $revision;
    }

    public function nextNumber(int $projectId): string
    {
        $latest = Boq::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('boq_number');

        $sequence = 1;

        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('BOQ-%03d', $sequence);
    }

    public function createNext(Boq $boq, User $user, ?string $reason = null): BoqRevision
    {
        return DB::transaction(function () use ($boq, $user, $reason) {
            $boq->refresh();
            $source = $boq->currentRevision()->first();

            if (! $source || ! $source->isFinalized()) {
                throw ValidationException::withMessages([
                    'revision' => 'Finalize the current BOQ revision before creating another.',
                ]);
            }

            if ($boq->revisions()->where('status', BoqRevision::DRAFT)->exists()) {
                throw ValidationException::withMessages([
                    'revision' => 'Finish the open draft revision before creating another.',
                ]);
            }

            $nextNumber = ((int) $boq->revisions()->max('revision_number')) + 1;
            $revision = $boq->revisions()->create([
                'revision_number' => $nextNumber,
                'revision_date' => now()->toDateString(),
                'revision_reason' => $reason,
                'status' => BoqRevision::DRAFT,
                'created_by' => $user->id,
            ]);

            $this->copyItems($source, $revision);

            return $revision;
        });
    }

    public function finalize(Boq $boq, BoqRevision $revision): void
    {
        DB::transaction(function () use ($boq, $revision): void {
            abort_unless($revision->boq_id === $boq->id, 404);

            if (! $revision->isDraft()) {
                throw ValidationException::withMessages([
                    'revision' => 'This revision is already finalized.',
                ]);
            }

            $revision->update([
                'status' => BoqRevision::FINALIZED,
                'revision_date' => now()->toDateString(),
            ]);

            $boq->update(['current_revision_id' => $revision->id]);
        });
    }

    public function editableRevision(Boq $boq): ?BoqRevision
    {
        return $boq->revisions()
            ->where('status', BoqRevision::DRAFT)
            ->orderByDesc('revision_number')
            ->first();
    }

    private function copyItems(BoqRevision $source, BoqRevision $target): void
    {
        $idMap = [];
        $items = $source->items()->with('budgets')->orderBy('sort_order')->orderBy('id')->get();

        foreach ($items as $item) {
            $copy = $target->items()->create([
                'boq_id' => $source->boq_id,
                'parent_id' => null,
                'sort_order' => $item->sort_order,
                'item_number' => $item->item_number,
                'service_code' => $item->service_code,
                'is_section' => $item->is_section,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => $item->is_section ? null : $item->quantity,
                'rate' => $item->is_section ? null : $item->rate,
            ]);

            foreach ($item->budgets as $budget) {
                $copy->budgets()->create([
                    'name' => $budget->name,
                    'amount' => $budget->amount,
                    'sort_order' => $budget->sort_order,
                ]);
            }

            $idMap[$item->id] = $copy->id;
        }

        foreach ($items as $item) {
            if ($item->parent_id && isset($idMap[$item->id], $idMap[$item->parent_id])) {
                BoqItem::query()->whereKey($idMap[$item->id])->update([
                    'parent_id' => $idMap[$item->parent_id],
                ]);
            }
        }
    }
}
