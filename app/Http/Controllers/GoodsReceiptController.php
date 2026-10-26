<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptImage;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\DocumentPdf;
use App\Services\GoodsReceiptService;
use App\Services\PoReceiptProgress;
use App\Support\Listing;
use App\Support\PdfFormat;
use App\Support\StoredUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private GoodsReceiptService $receipts,
        private PoReceiptProgress $progress,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $filters = Listing::filters($request);
        $status = $request->string('status')->toString();

        $query = MaterialReceipt::query()
            ->where('project_id', $project->id)
            ->where('document_kind', MaterialReceipt::KIND_GRN)
            ->with(['purchaseOrder.vendor', 'vendor', 'items', 'creator'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('purchase_order_id'), fn ($query) => $query->where('purchase_order_id', $request->integer('purchase_order_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('receipt_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('receipt_date', '<=', $request->string('to')))
            ->latest('receipt_date')
            ->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'receipt_number', 'challan_number', 'receipt_date', 'status',
            'purchaseOrder.po_number', 'purchaseOrder.vendor_name_snapshot', 'vendor.name', 'creator.name',
        ]);
        $rows = Listing::paginate($query, $request);

        return Inertia::render('Grns/Index', [
            'project' => $this->projectCard($project),
            'filters' => [
                ...$filters,
                'status' => $status,
                'vendor_id' => $request->integer('vendor_id') ?: null,
                'purchase_order_id' => $request->integer('purchase_order_id') ?: null,
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'orders' => $project->purchaseOrders()->orderByDesc('po_date')->get(['id', 'po_number']),
            'receipts' => $rows->through(fn (MaterialReceipt $receipt) => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'po_number' => $receipt->purchaseOrder?->po_number,
                'vendor' => $receipt->purchaseOrder?->vendor_name_snapshot ?: $receipt->vendor?->name,
                'ordered' => $this->sum($receipt, 'ordered'),
                'received' => $this->sum($receipt, 'quantity_received'),
                'accepted' => $this->sum($receipt, 'quantity_accepted'),
                'rejected' => $this->sum($receipt, 'quantity_rejected'),
                'created_by' => $receipt->creator?->name,
                'status' => $receipt->status,
            ]),
        ]);
    }

    public function create(Project $project, PurchaseOrder $purchaseOrder): Response
    {
        abort_unless((int) $purchaseOrder->project_id === (int) $project->id, 404);
        abort_unless($purchaseOrder->status === 'approved', 403);

        return Inertia::render('Grns/Form', [
            'project' => $this->projectCard($project),
            'order' => $this->orderPayload($purchaseOrder),
            'receipt' => null,
            'materials' => $this->materials(),
        ]);
    }

    public function store(Request $request, Project $project, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $receipt = $this->receipts->save($project, $purchaseOrder, null, $this->header($request), $this->rows($request), $request->user()->id);
        $this->storeImages($request, $project, $receipt);

        return redirect()->route('projects.grns.show', [$project, $receipt])->with('success', 'Goods receipt note saved.');
    }

    public function show(Request $request, Project $project, MaterialReceipt $receipt): Response
    {
        $this->ensureGrn($project, $receipt);
        $receipt->load(['items.material', 'items.purchaseOrderItem', 'purchaseOrder.vendor', 'vendor', 'creator', 'poster', 'images']);

        return Inertia::render('Grns/Show', [
            'project' => $this->projectCard($project),
            'company' => CompanySetting::current()?->document(),
            'receipt' => $this->payload($project, $receipt),
            'receiptStatus' => $receipt->purchaseOrder ? $this->progress->label($receipt->purchaseOrder) : null,
            'canSubmit' => $receipt->status === MaterialReceipt::STATUS_DRAFT,
            'canEdit' => $receipt->status === MaterialReceipt::STATUS_DRAFT,
            'canDecide' => $request->user()?->allows('grn.post') && $receipt->status === MaterialReceipt::STATUS_SUBMITTED,
            'canCancel' => $request->user()?->allows('grn.post') && $receipt->status === MaterialReceipt::STATUS_POSTED,
        ]);
    }

    public function edit(Project $project, MaterialReceipt $receipt): Response
    {
        $this->ensureGrn($project, $receipt);
        abort_unless($receipt->status === MaterialReceipt::STATUS_DRAFT, 403);
        $receipt->load(['items', 'purchaseOrder.items', 'purchaseOrder.vendor']);

        return Inertia::render('Grns/Form', [
            'project' => $this->projectCard($project),
            'order' => $this->orderPayload($receipt->purchaseOrder, $receipt->id),
            'receipt' => $this->payload($project, $receipt),
            'materials' => $this->materials(),
        ]);
    }

    public function update(Request $request, Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureGrn($project, $receipt);
        $this->receipts->save($project, $receipt->purchaseOrder, $receipt, $this->header($request), $this->rows($request), $request->user()->id);
        $this->storeImages($request, $project, $receipt);

        return redirect()->route('projects.grns.show', [$project, $receipt])->with('success', 'Goods receipt note updated.');
    }

    public function submit(Request $request, Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureGrn($project, $receipt);
        $this->receipts->submit($receipt, $request->user()->id);

        return back()->with('success', 'Goods receipt note submitted.');
    }

    public function post(Request $request, Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureGrn($project, $receipt);
        $this->receipts->post($receipt, $request->user()->id);

        return back()->with('success', 'Goods receipt note posted.');
    }

    public function reject(Request $request, Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureGrn($project, $receipt);
        $reason = $request->validate(['rejection_reason' => ['required', 'string', 'max:5000']])['rejection_reason'];
        $this->receipts->reject($receipt, $request->user()->id, $reason);

        return back()->with('success', 'Goods receipt note rejected.');
    }

    public function cancel(Request $request, Project $project, MaterialReceipt $receipt): RedirectResponse
    {
        $this->ensureGrn($project, $receipt);
        $this->receipts->cancel($receipt, $request->user()->id);

        return back()->with('success', 'Goods receipt note cancelled and stock reversed.');
    }

    public function pdf(Project $project, MaterialReceipt $receipt, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $receipt, $pdf, false);
    }

    public function downloadPdf(Project $project, MaterialReceipt $receipt, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $receipt, $pdf, true);
    }

    public function image(Project $project, MaterialReceipt $receipt, MaterialReceiptImage $image): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->ensureGrn($project, $receipt);
        abort_unless($image->material_receipt_id === $receipt->id, 404);
        abort_unless(Storage::disk('local')->exists($image->image_path), 404);

        return StoredUpload::serve(
            $image->image_path,
            inlineIfSafe: true,
            expectedPrefix: 'projects/'.$project->id.'/receipts',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Request $request): array
    {
        $validated = $request->validate([
            'receipt_date' => ['required', 'date'],
            'received_by' => ['required', 'string', 'max:255'],
            'challan_number' => ['nullable', 'string', 'max:255'],
            'challan_date' => ['nullable', 'date'],
            'vehicle_number' => ['nullable', 'string', 'max:255'],
            'inspection_notes' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array'],
            'images.*' => StoredUpload::rules(StoredUpload::IMAGES, 5120),
        ]);

        return $validated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.material_id' => ['nullable', 'integer'],
            'items.*.quantity_received' => ['nullable', 'numeric'],
            'items.*.quantity_accepted' => ['nullable', 'numeric'],
            'items.*.quantity_rejected' => ['nullable', 'numeric'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ])['items'];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(PurchaseOrder $order, ?int $exceptReceiptId = null): array
    {
        $order->loadMissing(['items', 'vendor']);

        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'vendor' => $order->vendor_name_snapshot ?: $order->vendor?->name ?: $order->supplier_name,
            'receipt_status' => $this->progress->label($order),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'unit' => $item->unit,
                'ordered' => (string) $item->quantity,
                'previously_received' => $this->progress->committed($item->id, $exceptReceiptId),
                'remaining' => $this->progress->remaining($item, $exceptReceiptId),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Project $project, MaterialReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'receipt_date' => $receipt->receipt_date?->toDateString(),
            'status' => $receipt->status,
            'po_number' => $receipt->purchaseOrder?->po_number,
            'purchase_order_id' => $receipt->purchase_order_id,
            'vendor' => $receipt->purchaseOrder?->vendor_name_snapshot ?: $receipt->vendor?->name,
            'received_by' => $receipt->received_by,
            'challan_number' => $receipt->challan_number,
            'challan_date' => $receipt->challan_date?->toDateString(),
            'vehicle_number' => $receipt->vehicle_number,
            'inspection_notes' => $receipt->inspection_notes,
            'remarks' => $receipt->remarks,
            'created_by' => $receipt->creator?->name,
            'posted_by' => $receipt->poster?->name,
            'posted_at' => $receipt->posted_at?->toDateTimeString(),
            'rejection_reason' => $receipt->rejection_reason,
            'items' => $receipt->items->map(fn ($item) => [
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'description' => $item->description,
                'material_id' => $item->material_id,
                'material' => $item->material ? trim($item->material->material_code.' '.$item->material->name) : null,
                'unit' => $item->unit,
                'ordered' => (string) ($item->purchaseOrderItem?->quantity ?? ''),
                'previously_received' => (string) $item->quantity_previously_received,
                'quantity_received' => (string) $item->quantity_received,
                'quantity_accepted' => (string) $item->quantity_accepted,
                'quantity_rejected' => (string) $item->quantity_rejected,
                'remarks' => $item->remarks,
            ])->values(),
            'images' => $receipt->relationLoaded('images') ? $receipt->images->map(fn (MaterialReceiptImage $image) => [
                'id' => $image->id,
                'url' => route('projects.grns.images.show', [$project, $receipt, $image]),
            ]) : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materials(): array
    {
        return Material::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Material $material) => [
            'id' => $material->id,
            'label' => $material->material_code.' '.$material->name,
            'unit' => $material->unit,
        ])->all();
    }

    private function storeImages(Request $request, Project $project, MaterialReceipt $receipt): void
    {
        foreach ($request->file('images', []) as $image) {
            $path = StoredUpload::store($image, 'projects/'.$project->id.'/receipts');
            $receipt->images()->create(['image_path' => $path]);
        }
    }

    private function renderPdf(Project $project, MaterialReceipt $receipt, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $this->ensureGrn($project, $receipt);
        $receipt->load(['items.material', 'items.purchaseOrderItem', 'purchaseOrder', 'vendor', 'creator', 'poster']);
        $payload = $this->payload($project, $receipt);
        $order = $receipt->purchaseOrder;
        $data = [
            'title' => 'GOODS RECEIPT NOTE',
            'number' => $payload['receipt_number'],
            'revision' => null,
            'dateLabel' => 'GRN date',
            'date' => PdfFormat::date($payload['receipt_date']),
            'status' => $payload['status'],
            'party' => [
                'label' => 'Vendor',
                'name' => $order?->vendor_name_snapshot ?: ($payload['vendor'] ?? ''),
                'lines' => array_values(array_filter([
                $order?->vendor_address_snapshot ? ['text' => $order->vendor_address_snapshot] : null,
                trim(($order?->vendor_city_snapshot ?? '').' '.($order?->vendor_state_snapshot ?? '')) !== ''
                    ? ['text' => trim(($order->vendor_city_snapshot ?? '').' '.($order->vendor_state_snapshot ?? ''))]
                    : null,
                $order?->vendor_gst_snapshot ? ['text' => 'GST: '.$order->vendor_gst_snapshot] : null,
            ])),
            ],
            'relatedLabel' => 'Purchase Order',
            'relatedValue' => $order?->po_number,
            'footerNote' => 'Goods receipt note',
            'projectName' => $project->project_name,
            'projectCode' => $project->project_code,
            'receipt' => $payload,
        ];

        return $download
            ? $pdf->download('pdf.goods-receipt', $data, $payload['receipt_number'].'.pdf')
            : $pdf->inline('pdf.goods-receipt', $data, $payload['receipt_number'].'.pdf');
    }

    private function sum(MaterialReceipt $receipt, string $field): string
    {
        if ($field === 'ordered') {
            $receipt->loadMissing('items.purchaseOrderItem');
            $total = '0.000';

            foreach ($receipt->items as $item) {
                $total = \App\Support\Money::add($total, (string) ($item->purchaseOrderItem?->quantity ?? 0), 3);
            }

            return $total;
        }

        $total = '0.000';

        foreach ($receipt->items as $item) {
            $total = \App\Support\Money::add($total, (string) $item->{$field}, 3);
        }

        return $total;
    }

    private function ensureGrn(Project $project, MaterialReceipt $receipt): void
    {
        abort_unless((int) $receipt->project_id === (int) $project->id, 404);
        abort_unless($receipt->isGrn(), 404);
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
