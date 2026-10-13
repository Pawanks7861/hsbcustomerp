<?php

namespace App\Http\Controllers;

use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptImage;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Vendor;
use App\Services\DocumentNumber;
use App\Services\DocumentPdf;
use App\Support\PdfFormat;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialReceiptController extends Controller
{
    public function index(Project $project): Response
    {
        $receipts = $project->materialReceipts()->with(['vendor', 'purchaseOrder', 'items', 'images'])->latest('receipt_date')->get();

        return Inertia::render('Store/Index', [
            'project' => $this->projectCard($project),
            'receipts' => $receipts->map(fn (MaterialReceipt $receipt) => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'type' => $receipt->typeLabel(),
                'po_number' => $receipt->purchaseOrder?->po_number,
                'vendor' => $receipt->vendor?->name,
                'items' => $receipt->items->count(),
                'received_by' => $receipt->received_by,
                'images' => $receipt->images->count(),
            ]),
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        $order = $this->orderForProject($project, $request->integer('purchase_order_id') ?: null);

        return Inertia::render('Store/Form', [
            'project' => $this->projectCard($project),
            'receipt' => null,
            'purchaseOrders' => $this->orderOptions($project),
            'vendors' => Vendor::query()->where('status', Vendor::ACTIVE)->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'selectedOrder' => $order ? $this->orderPayload($order) : null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $receipt = $this->persist($request, $project, null);

        return redirect()
            ->route('projects.store.show', [$project, $receipt])
            ->with('success', 'Material receipt recorded.');
    }

    public function show(Project $project, MaterialReceipt $receipt): Response
    {
        $this->ensureReceipt($project, $receipt);
        $receipt->load(['items', 'images', 'vendor', 'purchaseOrder']);

        return Inertia::render('Store/Show', [
            'project' => $this->projectCard($project),
            'receipt' => $this->receiptPayload($project, $receipt),
        ]);
    }

    public function pdf(Project $project, MaterialReceipt $receipt, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $receipt, $pdf, false);
    }

    public function downloadPdf(Project $project, MaterialReceipt $receipt, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $receipt, $pdf, true);
    }

    public function edit(Project $project, MaterialReceipt $receipt): Response
    {
        $this->ensureReceipt($project, $receipt);
        abort_if($receipt->isGrn(), 404);
        $receipt->load(['items', 'images', 'purchaseOrder.vendor', 'purchaseOrder.items']);

        return Inertia::render('Store/Form', [
            'project' => $this->projectCard($project),
            'receipt' => [
                'id' => $receipt->id,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'purchase_order_id' => $receipt->purchase_order_id,
                'vendor_id' => $receipt->vendor_id,
                'received_by' => $receipt->received_by,
                'remarks' => $receipt->remarks,
                'items' => $receipt->items->map(fn ($item) => [
                    'purchase_order_item_id' => $item->purchase_order_item_id,
                    'description' => $item->description,
                    'unit' => $item->unit ?? '',
                    'quantity_received' => (string) $item->quantity_received,
                    'remarks' => $item->remarks ?? '',
                ]),
                'images' => $receipt->images->map(fn (MaterialReceiptImage $image) => [
                    'id' => $image->id,
                    'url' => route('projects.store.images.show', [$project, $receipt, $image]),
                ]),
            ],
            'purchaseOrders' => $this->orderOptions($project),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'selectedOrder' => $receipt->purchaseOrder ? $this->orderPayload($receipt->purchaseOrder) : null,
        ]);
    }

    public function update(Request $request, Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureReceipt($project, $receipt);
        abort_if($receipt->isGrn(), 404);
        $this->persist($request, $project, $receipt);

        return redirect()->route('projects.store.show', [$project, $receipt])->with('success', 'Receipt updated.');
    }

    public function destroy(Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureReceipt($project, $receipt);
        abort_if($receipt->isGrn() || $receipt->status === MaterialReceipt::STATUS_POSTED, 403);

        foreach ($receipt->images as $image) {
            Storage::disk('local')->delete($image->image_path);
        }

        $receipt->delete();

        return redirect()->route('projects.store.index', $project)->with('success', 'Receipt deleted.');
    }

    public function image(Project $project, MaterialReceipt $receipt, MaterialReceiptImage $image): StreamedResponse
    {
        $this->ensureReceipt($project, $receipt);
        abort_unless($image->material_receipt_id === $receipt->id, 404);
        abort_unless(Storage::disk('local')->exists($image->image_path), 404);

        return Storage::disk('local')->response($image->image_path);
    }

    private function persist(Request $request, Project $project, ?MaterialReceipt $receipt): MaterialReceipt
    {
        $validated = $request->validate([
            'receipt_date' => ['required', 'date'],
            'purchase_order_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'received_by' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity_received' => ['nullable', 'numeric', 'gte:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $order = $this->orderForProject($project, $validated['purchase_order_id'] ?? null);
        $lines = $this->lines($validated['items'], $order);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Enter at least one received quantity.',
            ]);
        }

        $vendorId = $order?->vendor_id ?: ($validated['vendor_id'] ?? null);

        $save = function () use ($request, $project, $receipt, $validated, $order, $lines, $vendorId) {
            $attributes = [
                'project_id' => $project->id,
                'purchase_order_id' => $order?->id,
                'vendor_id' => $vendorId,
                'receipt_date' => $validated['receipt_date'],
                'received_by' => $validated['received_by'],
                'remarks' => $validated['remarks'] ?? null,
            ];

            if ($receipt) {
                $receipt->update($attributes);
                $receipt->items()->delete();
            } else {
                $receipt = MaterialReceipt::query()->create([
                    ...$attributes,
                    'receipt_number' => DocumentNumber::next(MaterialReceipt::class, 'receipt_number', 'MR'),
                    'document_kind' => MaterialReceipt::KIND_STORE,
                    'status' => MaterialReceipt::STATUS_LEGACY,
                    'created_by' => $request->user()->id,
                ]);
            }

            $receipt->items()->createMany($lines);
            $this->storeImages($request, $project, $receipt);

            return $receipt;
        };

        if ($receipt) {
            return DB::transaction($save);
        }

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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $rows, ?PurchaseOrder $order): array
    {
        if ($order) {
            $order->loadMissing('items');
            $lines = [];

            foreach ($rows as $row) {
                $quantity = Money::scale($row['quantity_received'] ?? null, 3);

                if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                    continue;
                }

                $item = $order->items->firstWhere('id', (int) ($row['purchase_order_item_id'] ?? 0));

                if (! $item instanceof PurchaseOrderItem) {
                    throw ValidationException::withMessages([
                        'items' => 'Received lines must belong to the selected purchase order.',
                    ]);
                }

                $lines[] = [
                    'purchase_order_item_id' => $item->id,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'quantity_received' => $quantity,
                    'remarks' => $this->nullable($row['remarks'] ?? null),
                ];
            }

            return $lines;
        }

        $lines = [];

        foreach ($rows as $row) {
            $quantity = Money::scale($row['quantity_received'] ?? null, 3);
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '' && ($quantity === null || bccomp($quantity, '0', 3) !== 1)) {
                continue;
            }

            if ($description === '' || $quantity === null || bccomp($quantity, '0', 3) !== 1) {
                throw ValidationException::withMessages([
                    'items' => 'Each manual line needs a description and a quantity received.',
                ]);
            }

            $lines[] = [
                'purchase_order_item_id' => null,
                'description' => $description,
                'unit' => $this->nullable($row['unit'] ?? null),
                'quantity_received' => $quantity,
                'remarks' => $this->nullable($row['remarks'] ?? null),
            ];
        }

        return $lines;
    }

    private function storeImages(Request $request, Project $project, MaterialReceipt $receipt): void
    {
        foreach ($request->file('images', []) as $image) {
            $name = Str::uuid()->toString().'.'.strtolower($image->getClientOriginalExtension());
            $path = $image->storeAs('projects/'.$project->id.'/receipts', $name, 'local');
            $receipt->images()->create(['image_path' => $path]);
        }
    }

    private function orderForProject(Project $project, ?int $orderId): ?PurchaseOrder
    {
        if (! $orderId) {
            return null;
        }

        $order = PurchaseOrder::query()->with(['items', 'vendor'])->findOrFail($orderId);
        abort_unless($order->project_id === $project->id, 404);

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderOptions(Project $project): array
    {
        return $project->purchaseOrders()->with('vendor')->orderByDesc('po_date')->get()->map(fn (PurchaseOrder $order) => [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'vendor' => $order->vendor?->name ?? $order->supplier_name,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'vendor_id' => $order->vendor_id,
            'vendor' => $order->vendor?->name ?? $order->vendor_name_snapshot ?? $order->supplier_name,
            'items' => $order->items->map(fn (PurchaseOrderItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => (string) $item->quantity,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptPayload(Project $project, MaterialReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'receipt_date' => $receipt->receipt_date?->toDateString(),
            'type' => $receipt->typeLabel(),
            'po_number' => $receipt->purchaseOrder?->po_number,
            'vendor' => $receipt->vendor?->name,
            'received_by' => $receipt->received_by,
            'remarks' => $receipt->remarks,
            'items' => $receipt->items->map(fn ($item) => [
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity_received' => (string) $item->quantity_received,
                'remarks' => $item->remarks,
            ]),
            'images' => $receipt->images->map(fn (MaterialReceiptImage $image) => [
                'id' => $image->id,
                'url' => route('projects.store.images.show', [$project, $receipt, $image]),
                'caption' => $image->caption,
            ]),
        ];
    }

    private function renderPdf(Project $project, MaterialReceipt $receipt, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $this->ensureReceipt($project, $receipt);
        $receipt->load(['items', 'images', 'vendor', 'purchaseOrder']);
        $payload = $this->receiptPayload($project, $receipt);

        $data = [
            'title' => 'MATERIAL RECEIPT',
            'number' => $payload['receipt_number'],
            'revision' => null,
            'dateLabel' => 'Receipt date',
            'date' => PdfFormat::date($payload['receipt_date']),
            'status' => null,
            'party' => filled($payload['vendor']) ? [
                'label' => 'Vendor',
                'name' => $payload['vendor'],
                'lines' => [],
            ] : null,
            'relatedLabel' => filled($payload['po_number']) ? 'Purchase Order' : null,
            'relatedValue' => $payload['po_number'] ?? '',
            'footerNote' => 'Material receipt',
            'projectName' => $project->project_name,
            'projectCode' => $project->project_code,
            'receipt' => $payload,
        ];

        $filename = $payload['receipt_number'].'.pdf';

        return $download
            ? $pdf->download('pdf.material-receipt', $data, $filename)
            : $pdf->inline('pdf.material-receipt', $data, $filename);
    }

    private function ensureReceipt(Project $project, MaterialReceipt $receipt): void
    {
        abort_unless($receipt->project_id === $project->id, 404);
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
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
