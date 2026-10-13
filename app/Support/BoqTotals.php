<?php

namespace App\Support;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\Project;

class BoqTotals
{
    public static function project(Project $project): array
    {
        $project->loadMissing(['boqs.currentItems.budgets', 'boqs.currentItems.children']);

        $boqTotal = '0.00';
        $budgetTotal = '0.00';

        foreach ($project->boqs as $boq) {
            $boqTotal = Money::add($boqTotal, $boq->grandTotal());
            $budgetTotal = Money::add($budgetTotal, $boq->budgetTotal());
        }

        return [
            'boq_total' => $boqTotal,
            'budget_total' => $budgetTotal,
        ];
    }

    public static function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
            'description' => $project->description,
            ...self::project($project),
        ];
    }

    public static function boq(Boq $boq, ?BoqRevision $revision = null): array
    {
        $revision ??= $boq->currentRevision;
        $items = $revision
            ? $revision->items()->with(['budgets', 'children'])->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        $subtotal = '0.00';
        $budgetTotal = '0.00';

        foreach ($items as $item) {
            if ($item->contributesToSubtotal()) {
                $subtotal = Money::add($subtotal, (string) $item->amount);
            }

            $budgetTotal = Money::add($budgetTotal, $item->budgetTotal());
        }

        $gstAmount = Money::percentage($subtotal, (string) $boq->gst_percentage);
        $procurementRevisionId = $boq->procurementRevisionId();

        return [
            'id' => $boq->id,
            'project_id' => $boq->project_id,
            'boq_number' => $boq->boq_number,
            'title' => $boq->title,
            'gst_percentage' => (string) $boq->gst_percentage,
            'subtotal' => $subtotal,
            'gst_amount' => $gstAmount,
            'grand_total' => Money::add($subtotal, $gstAmount),
            'budget_total' => $budgetTotal,
            'revision_id' => $revision?->id,
            'revision_number' => $revision?->revision_number,
            'revision_date' => $revision?->revision_date?->toDateString(),
            'revision_status' => $revision?->status,
            'revision_reason' => $revision?->revision_reason,
            'editable' => (bool) $revision?->isDraft(),
            'is_current' => $revision && (int) $boq->current_revision_id === (int) $revision->id,
            'items' => $items->map(function (BoqItem $item) use ($procurementRevisionId) {
                $payload = self::item($item);
                $payload['procurable'] = $item->isProcurable() && (int) $item->boq_revision_id === (int) $procurementRevisionId;

                return $payload;
            })->values()->all(),
        ];
    }

    public static function item(BoqItem $item): array
    {
        $item->loadMissing(['budgets', 'children']);

        return [
            'id' => $item->id,
            'parent_id' => $item->parent_id,
            'sort_order' => $item->sort_order,
            'item_number' => $item->item_number,
            'service_code' => $item->service_code,
            'is_section' => (bool) $item->is_section,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'rate' => $item->rate,
            'amount' => $item->amount,
            'has_children' => $item->children->isNotEmpty(),
            'procurable' => $item->isProcurable(),
            'budget_total' => $item->budgetTotal(),
            'budgets' => $item->budgets->map(fn ($budget) => [
                'id' => $budget->id,
                'name' => $budget->name,
                'amount' => (string) $budget->amount,
                'sort_order' => $budget->sort_order,
                'is_default' => $budget->isDefault(),
            ])->values()->all(),
        ];
    }
}
