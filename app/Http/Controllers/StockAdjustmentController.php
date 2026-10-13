<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\StockLedgerEntry;
use App\Services\DocumentNumber;
use App\Services\InventoryStock;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAdjustmentController extends Controller
{
    public function __construct(private InventoryStock $stock) {}

    public function index(Project $project): Response
    {
        $adjustments = $project->stockAdjustments()->with('material')->latest('adjustment_date')->get();

        return Inertia::render('Inventory/Adjustments/Index', [
            'project' => $this->projectCard($project),
            'adjustments' => $adjustments->map(fn (StockAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'adjustment_number' => $adjustment->adjustment_number,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                'material' => trim($adjustment->material?->material_code.' '.$adjustment->material?->name),
                'adjustment_type' => $adjustment->adjustment_type,
                'quantity' => (string) $adjustment->quantity,
                'status' => $adjustment->status,
            ]),
        ]);
    }

    public function create(Project $project): Response
    {
        return Inertia::render('Inventory/Adjustments/Form', [
            'project' => $this->projectCard($project),
            'materials' => Material::query()->where('is_active', true)->orderBy('name')->get(['id', 'material_code', 'name', 'unit']),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $adjustment = $this->persist($request, $project);

        return redirect()->route('projects.inventory.adjustments.show', [$project, $adjustment])->with('success', 'Stock adjustment saved.');
    }

    public function show(Project $project, StockAdjustment $stockAdjustment): Response
    {
        $this->ensure($project, $stockAdjustment);
        $stockAdjustment->load(['material', 'creator', 'postedBy']);

        return Inertia::render('Inventory/Adjustments/Show', [
            'project' => $this->projectCard($project),
            'adjustment' => [
                'id' => $stockAdjustment->id,
                'adjustment_number' => $stockAdjustment->adjustment_number,
                'adjustment_date' => $stockAdjustment->adjustment_date?->toDateString(),
                'material' => trim($stockAdjustment->material?->material_code.' '.$stockAdjustment->material?->name),
                'unit' => $stockAdjustment->material?->unit,
                'adjustment_type' => $stockAdjustment->adjustment_type,
                'quantity' => (string) $stockAdjustment->quantity,
                'reason' => $stockAdjustment->reason,
                'status' => $stockAdjustment->status,
                'created_by' => $stockAdjustment->creator?->name,
                'posted_by' => $stockAdjustment->postedBy?->name,
                'posted_at' => $stockAdjustment->posted_at?->toDateTimeString(),
                'has_attachment' => filled($stockAdjustment->attachment_path),
            ],
        ]);
    }

    public function attachment(Project $project, StockAdjustment $stockAdjustment): StreamedResponse
    {
        $this->ensure($project, $stockAdjustment);
        abort_unless(filled($stockAdjustment->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($stockAdjustment->attachment_path), 404);

        return Storage::disk('local')->response($stockAdjustment->attachment_path);
    }

    public function post(Request $request, Project $project, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $this->ensure($project, $stockAdjustment);

        DB::transaction(function () use ($request, $project, $stockAdjustment): void {
            $locked = StockAdjustment::query()->whereKey($stockAdjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === StockAdjustment::DRAFT, 403);
            $quantity = Money::scale($locked->quantity, 3) ?? '0.000';
            $in = $locked->increasesStock();

            $this->stock->move(
                $project->id,
                (int) $locked->material_id,
                $locked->adjustment_date->toDateString(),
                $in ? StockLedgerEntry::ADJUSTMENT_IN : StockLedgerEntry::ADJUSTMENT_OUT,
                'stock_adjustment',
                (int) $locked->id,
                (int) $locked->id,
                $locked->adjustment_number,
                $in ? $quantity : '0',
                $in ? '0' : $quantity,
                $request->user()->id,
                $locked->reason,
            );

            $locked->update([
                'status' => StockAdjustment::POSTED,
                'posted_at' => now(),
                'posted_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Stock adjustment posted.');
    }

    private function persist(Request $request, Project $project): StockAdjustment
    {
        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'adjustment_date' => ['required', 'date'],
            'adjustment_type' => ['required', Rule::in([StockAdjustment::OPENING, StockAdjustment::INCREASE, StockAdjustment::DECREASE])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ]);

        $material = Material::query()->findOrFail($validated['material_id']);
        abort_unless($material->is_active, 422);
        $path = $request->file('attachment')?->store('projects/'.$project->id.'/adjustments', 'local');

        $save = function () use ($request, $project, $validated, $path) {
            return StockAdjustment::query()->create([
                'project_id' => $project->id,
                'material_id' => $validated['material_id'],
                'adjustment_number' => DocumentNumber::next(StockAdjustment::class, 'adjustment_number', 'ADJ'),
                'adjustment_date' => $validated['adjustment_date'],
                'adjustment_type' => $validated['adjustment_type'],
                'quantity' => Money::scale($validated['quantity'], 3),
                'reason' => $validated['reason'],
                'attachment_path' => $path,
                'status' => StockAdjustment::DRAFT,
                'created_by' => $request->user()->id,
            ]);
        };

        $exception = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction($save);
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }
        }

        throw $exception;
    }

    private function ensure(Project $project, StockAdjustment $adjustment): void
    {
        abort_unless((int) $adjustment->project_id === (int) $project->id, 404);
    }

    /**
     * @return array{id: int, project_name: string, project_code: string}
     */
    private function projectCard(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
        ];
    }
}
