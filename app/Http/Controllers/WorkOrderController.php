<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderRequest;
use App\Models\CompanySetting;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\Access;
use App\Services\DocumentPdf;
use App\Services\WorkOrderService;
use App\Support\PdfFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WorkOrderController extends Controller
{
    public function __construct(private Access $access) {}

    public function index(Request $request, Project $project): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $orders = WorkOrder::query()
            ->withCount('items')
            ->where('project_id', $project->id)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('work_order_number', 'like', "%{$search}%")
                        ->orWhere('contractor_name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->get();

        return Inertia::render('WorkOrders/Index', [
            'project' => $this->projectCard($project),
            'filters' => ['search' => $search, 'status' => $status],
            'abilities' => $this->abilities($request->user()),
            'workOrders' => $orders->map(fn (WorkOrder $order) => [
                'id' => $order->id,
                'work_order_number' => $order->work_order_number,
                'work_order_date' => $order->work_order_date?->toDateString(),
                'contractor_name' => $order->contractor_name,
                'item_count' => $order->items_count,
                'grand_total' => (string) $order->grand_total,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'approval_label' => $order->approvalLabel(),
                'work_status' => $order->work_status,
                'work_label' => $order->workLabel(),
                'is_quantity_exceeded' => (bool) $order->is_quantity_exceeded,
            ])->values(),
        ]);
    }

    public function create(Request $request, Project $project, WorkOrderService $service): Response
    {
        return Inertia::render('WorkOrders/Form', $this->formProps($project, $service, null, (int) $request->query('boq_item_id')));
    }

    public function store(WorkOrderRequest $request, Project $project, WorkOrderService $service): RedirectResponse
    {
        $order = $service->save($project, $request->user(), $request->validated());

        return redirect()
            ->route('projects.work-orders.show', [$project, $order])
            ->with('success', 'Work order saved.');
    }

    public function show(Request $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): Response
    {
        $workOrder = $this->order($project, $workOrder);
        $workOrder->load(['items', 'approvedBy', 'rejectedBy', 'issuedBy', 'cancelledBy', 'amendedFrom']);

        return Inertia::render('WorkOrders/Show', [
            'project' => $this->projectCard($project),
            'company' => CompanySetting::current()?->document(),
            'workOrder' => $this->detail($workOrder),
            'history' => $service->history($workOrder),
            'abilities' => $this->documentAbilities($request->user(), $workOrder),
        ]);
    }

    public function edit(Project $project, WorkOrder $workOrder, WorkOrderService $service): Response
    {
        $workOrder = $this->order($project, $workOrder);
        abort_unless($workOrder->isDraft(), 403);

        return Inertia::render('WorkOrders/Form', $this->formProps($project, $service, $workOrder, null));
    }

    public function update(WorkOrderRequest $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $workOrder = $this->order($project, $workOrder);
        $service->save($project, $request->user(), $request->validated(), $workOrder);

        return redirect()
            ->route('projects.work-orders.show', [$project, $workOrder])
            ->with('success', 'Work order updated.');
    }

    public function submit(Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $service->submit($this->order($project, $workOrder), request()->user());

        return back()->with('success', 'Work order submitted for approval.');
    }

    public function approve(Request $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $service->approve($this->order($project, $workOrder), $request->user(), $request->input('approval_remarks'));

        return back()->with('success', 'Work order approved.');
    }

    public function reject(Request $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $service->reject($this->order($project, $workOrder), $request->user(), $request->input('rejection_reason'));

        return back()->with('success', 'Work order rejected.');
    }

    public function issue(Request $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $service->issue($this->order($project, $workOrder), $request->user(), $request->input('approval_remarks'));

        return back()->with('success', 'Work order issued to the contractor.');
    }

    public function execute(Request $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.executed_quantity' => ['required', 'numeric', 'gte:0'],
            'lines.*.execution_status' => ['required', 'string'],
            'execution_remarks' => ['nullable', 'string', 'max:5000'],
        ]);
        $service->execute($this->order($project, $workOrder), $request->user(), $data['lines'], $data['execution_remarks'] ?? null);

        return back()->with('success', 'Execution status updated.');
    }

    public function amend(Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $copy = $service->amend($this->order($project, $workOrder), request()->user());

        return redirect()
            ->route('projects.work-orders.edit', [$project, $copy])
            ->with('success', 'Amendment draft created. The issued work order is unchanged until this amendment is issued.');
    }

    public function cancel(Request $request, Project $project, WorkOrder $workOrder, WorkOrderService $service): RedirectResponse
    {
        $service->cancel($this->order($project, $workOrder), $request->user(), $request->input('cancellation_reason'));

        return back()->with('success', 'Work order cancelled.');
    }

    public function pdf(Project $project, WorkOrder $workOrder, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $workOrder, $pdf, false);
    }

    public function downloadPdf(Project $project, WorkOrder $workOrder, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf($project, $workOrder, $pdf, true);
    }

    private function renderPdf(Project $project, WorkOrder $workOrder, DocumentPdf $pdf, bool $download): HttpResponse
    {
        $workOrder = $this->order($project, $workOrder);
        $workOrder->load(['items', 'approvedBy', 'issuedBy']);
        $document = $this->detail($workOrder);
        $lines = [];

        foreach (preg_split('/\R/', (string) $workOrder->contractor_address) ?: [] as $line) {
            $text = trim($line);

            if ($text !== '') {
                $lines[] = ['text' => $text];
            }
        }

        if ($workOrder->contractor_phone) {
            $lines[] = ['text' => $workOrder->contractor_phone];
        }

        if ($workOrder->contractor_email) {
            $lines[] = ['text' => $workOrder->contractor_email];
        }

        if ($workOrder->contractor_gst) {
            $lines[] = ['label' => 'GST', 'text' => $workOrder->contractor_gst];
        }

        $data = [
            'title' => 'WORK ORDER',
            'number' => $document['work_order_number'],
            'revision' => null,
            'subject' => $project->project_name,
            'dateLabel' => 'Work order date',
            'date' => PdfFormat::date($document['work_order_date']),
            'status' => $document['status_label'],
            'party' => [
                'label' => 'Contractor',
                'name' => $workOrder->contractor_name,
                'lines' => $lines,
            ],
            'relatedLabel' => 'Project',
            'relatedValue' => $project->project_code,
            'footerNote' => 'Work order quantities are contractor allocations and are not material receipts.',
            'projectName' => $project->project_name,
            'document' => $document,
        ];
        $filename = $document['work_order_number'].'.pdf';

        return $download
            ? $pdf->download('pdf.work-order', $data, $filename)
            : $pdf->inline('pdf.work-order', $data, $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Project $project, WorkOrderService $service, ?WorkOrder $workOrder, ?int $preselectedId): array
    {
        $workOrder?->load('items');

        return [
            'project' => $this->projectCard($project),
            'workOrder' => $workOrder ? $this->detail($workOrder) : null,
            'contractors' => Vendor::query()->contractors()->orderBy('name')->get()->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
                'contact_person' => $vendor->contact_person,
                'phone' => $vendor->phone,
                'gst_number' => $vendor->gst_number,
            ])->values(),
            'catalog' => $service->catalog($project, $workOrder),
            'preselectedId' => $preselectedId ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(WorkOrder $workOrder): array
    {
        return [
            'id' => $workOrder->id,
            'work_order_number' => $workOrder->work_order_number,
            'work_order_date' => $workOrder->work_order_date?->toDateString(),
            'completion_date' => $workOrder->completion_date?->toDateString(),
            'vendor_id' => $workOrder->vendor_id,
            'contractor_code' => $workOrder->contractor_code,
            'contractor_name' => $workOrder->contractor_name,
            'contractor_contact' => $workOrder->contractor_contact,
            'contractor_phone' => $workOrder->contractor_phone,
            'contractor_email' => $workOrder->contractor_email,
            'contractor_address' => $workOrder->contractor_address,
            'contractor_gst' => $workOrder->contractor_gst,
            'scope' => $workOrder->scope,
            'payment_terms' => $workOrder->payment_terms,
            'terms_conditions' => $workOrder->terms_conditions,
            'remarks' => $workOrder->remarks,
            'subtotal' => (string) $workOrder->subtotal,
            'gst_percentage' => (string) $workOrder->gst_percentage,
            'gst_amount' => (string) $workOrder->gst_amount,
            'grand_total' => (string) $workOrder->grand_total,
            'status' => $workOrder->status,
            'status_label' => $workOrder->statusLabel(),
            'approval_label' => $workOrder->approvalLabel(),
            'work_status' => $workOrder->work_status,
            'work_label' => $workOrder->workLabel(),
            'is_quantity_exceeded' => (bool) $workOrder->is_quantity_exceeded,
            'approval_remarks' => $workOrder->approval_remarks,
            'approved_by' => $workOrder->approvedBy?->name,
            'approved_at' => $workOrder->approved_at?->toDateTimeString(),
            'rejection_reason' => $workOrder->rejection_reason,
            'rejected_by' => $workOrder->rejectedBy?->name,
            'rejected_at' => $workOrder->rejected_at?->toDateTimeString(),
            'issued_by' => $workOrder->issuedBy?->name,
            'issued_at' => $workOrder->issued_at?->toDateTimeString(),
            'cancellation_reason' => $workOrder->cancellation_reason,
            'cancelled_by' => $workOrder->cancelledBy?->name,
            'cancelled_at' => $workOrder->cancelled_at?->toDateTimeString(),
            'amended_from' => $workOrder->amendedFrom?->work_order_number,
            'items' => $workOrder->items->map(fn (WorkOrderItem $line) => [
                'id' => $line->id,
                'boq_item_id' => $line->boq_item_id,
                'boq_revision_id' => $line->boq_revision_id,
                'boq_number' => $line->boq_number,
                'revision_number' => $line->revision_number,
                'item_number' => $line->item_number,
                'service_code' => $line->service_code,
                'description' => $line->description,
                'unit' => $line->unit,
                'boq_quantity' => (string) $line->boq_quantity,
                'previously_allocated' => (string) $line->previously_allocated,
                'quantity' => (string) $line->quantity,
                'remaining_quantity' => bcsub(bcsub((string) $line->boq_quantity, (string) $line->previously_allocated, 3), (string) $line->quantity, 3),
                'rate' => (string) $line->rate,
                'amount' => (string) $line->amount,
                'excess_quantity' => (string) $line->excess_quantity,
                'is_exceeded' => (bool) $line->is_exceeded,
                'executed_quantity' => (string) $line->executed_quantity,
                'execution_status' => $line->execution_status,
            ])->values(),
        ];
    }

    private function order(Project $project, WorkOrder $workOrder): WorkOrder
    {
        abort_unless($workOrder->project_id === $project->id, 404);

        return $workOrder;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        return [
            'view' => $this->access->allows($user, 'work_orders.view'),
            'create' => $this->access->allows($user, 'work_orders.create'),
            'edit' => $this->access->allows($user, 'work_orders.edit'),
            'submit' => $this->access->allows($user, 'work_orders.submit'),
            'approve' => $this->access->allows($user, 'work_orders.approve'),
            'reject' => $this->access->allows($user, 'work_orders.reject'),
            'issue' => $this->access->allows($user, 'work_orders.issue'),
            'execute' => $this->access->allows($user, 'work_orders.execute'),
            'amend' => $this->access->allows($user, 'work_orders.amend'),
            'cancel' => $this->access->allows($user, 'work_orders.cancel'),
            'print' => $this->access->allows($user, 'work_orders.print'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentAbilities(User $user, WorkOrder $workOrder): array
    {
        $abilities = $this->abilities($user);
        $status = $workOrder->status;

        return [
            'edit' => $abilities['edit'] && $status === WorkOrder::DRAFT,
            'submit' => $abilities['submit'] && $status === WorkOrder::DRAFT,
            'approve' => $abilities['approve'] && $status === WorkOrder::SUBMITTED,
            'reject' => $abilities['reject'] && $status === WorkOrder::SUBMITTED,
            'issue' => $abilities['issue'] && $status === WorkOrder::APPROVED,
            'execute' => $abilities['execute'] && in_array($status, [WorkOrder::ISSUED, WorkOrder::IN_PROGRESS], true),
            'amend' => $abilities['amend'] && in_array($status, [WorkOrder::ISSUED, WorkOrder::IN_PROGRESS, WorkOrder::COMPLETED], true),
            'cancel' => $abilities['cancel'] && ! in_array($status, [WorkOrder::CANCELLED, WorkOrder::SUPERSEDED], true),
            'print' => $abilities['print'],
        ];
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
