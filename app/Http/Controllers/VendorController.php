<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorRequest;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\VendorBalanceService;
use App\Support\Listing;
use App\Support\VendorRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function index(Request $request, Access $access): Response
    {
        $filters = Listing::filters($request);
        $status = in_array($request->query('status'), [Vendor::ACTIVE, Vendor::INACTIVE], true) ? (string) $request->query('status') : '';

        $query = Vendor::query()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->withExists(Vendor::TRANSACTION_RELATIONS)
            ->orderBy('name')
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], ['vendor_code', 'name', 'phone', 'gst_number', 'status', 'city', 'contact_person']);

        $canEdit = $access->allows($request->user(), 'vendors.edit');

        return Inertia::render('Vendors/Index', [
            'vendors' => Listing::paginate($query, $request)->through(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
                'phone' => $vendor->phone,
                'gst_number' => $vendor->gst_number,
                'status' => $vendor->status,
                'profile_complete' => VendorRules::profileIsComplete($vendor),
                'has_transactions' => collect(Vendor::TRANSACTION_RELATIONS)
                    ->contains(fn (string $relation) => (bool) $vendor->getAttribute(Str::snake($relation).'_exists')),
            ]),
            'filters' => [...$filters, 'status' => $status],
            'can' => ['edit' => $canEdit, 'delete' => $canEdit],
        ]);
    }

    public function create(Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.create'), 403);

        return Inertia::render('Vendors/Form', [
            'vendor' => null,
        ]);
    }

    public function store(VendorRequest $request, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'vendors.create'), 403);

        $vendor = DB::transaction(function () use ($request) {
            return Vendor::query()->create([
                ...$request->validated(),
                'vendor_code' => $this->nextCode(),
                'status' => Vendor::ACTIVE,
            ]);
        });

        return redirect()->route('vendors.show', $vendor)->with('success', 'Vendor created.');
    }

    public function show(Vendor $vendor, VendorBalanceService $balances, Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.view'), 403);

        $scope = $access->projectScope($request->user(), $request->integer('project_id') ?: null);
        $projectIds = $scope['project_ids'];
        $projectId = $scope['project_id'];
        $inVisibleProjects = function ($query) use ($projectId, $projectIds) {
            if ($projectId !== null) {
                return $query->where('project_id', $projectId);
            }
            if ($projectIds !== null) {
                return $query->whereIn('project_id', $projectIds ?: [0]);
            }

            return $query;
        };

        $summary = $balances->summary($vendor, $projectId, null, null, $projectIds);
        $filters = Listing::filters($request);
        $search = $filters['search'];

        $orders = $inVisibleProjects($vendor->purchaseOrders())->with('project')->latest('po_date')->orderByDesc('id');
        Listing::applySearch($orders, $search, ['po_number', 'po_date', 'status', 'grand_total', 'project.project_name']);
        $invoices = $inVisibleProjects($vendor->invoices())->with(['project', 'purchaseOrder'])->latest('invoice_date')->orderByDesc('id');
        Listing::applySearch($invoices, $search, ['invoice_number', 'invoice_date', 'total_amount', 'status', 'project.project_name', 'purchaseOrder.po_number']);
        $paymentColumns = ['payment_date', 'payment_type', 'payment_mode', 'amount', 'reference_number', 'remarks'];
        $payments = $inVisibleProjects($vendor->payments())->with('project')->latest('payment_date')->orderByDesc('id');
        Listing::applySearch($payments, $search, $paymentColumns);
        $advances = $inVisibleProjects($vendor->payments()->where('payment_type', 'advance'))->latest('payment_date')->orderByDesc('id');
        Listing::applySearch($advances, $search, $paymentColumns);

        return Inertia::render('Vendors/Show', [
            'vendor' => $this->payload($vendor),
            'summary' => $summary,
            'tab' => $request->string('tab')->toString() ?: 'overview',
            'filters' => $filters,
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'purchaseOrders' => Listing::paginate($orders, $request)->through(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'project_id' => $order->project_id,
                'project_name' => $order->project?->project_name,
                'po_number' => $order->po_number,
                'po_date' => $order->po_date?->toDateString(),
                'status' => $order->status,
                'grand_total' => (string) $order->grand_total,
            ]),
            'invoices' => Listing::paginate($invoices, $request)->through(fn (PurchaseInvoice $invoice) => [
                'id' => $invoice->id,
                'project_id' => $invoice->project_id,
                'project_name' => $invoice->project?->project_name,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'po_number' => $invoice->purchaseOrder?->po_number,
                'total_amount' => (string) $invoice->total_amount,
                'outstanding' => $balances->invoiceOutstanding($invoice),
                'status' => $invoice->status,
            ]),
            'payments' => Listing::paginate($payments, $request),
            'advances' => Listing::paginate($advances, $request),
            'refunds' => [
                'recoveries' => $inVisibleProjects($vendor->recoveries())->latest()->get(),
                'received' => $inVisibleProjects($vendor->refunds())->latest('refund_date')->get(),
            ],
            'transactions' => Listing::paginateRows(
                $balances->transactions($vendor, $projectIds ?? ($projectId !== null ? [$projectId] : null)),
                $request,
                ['date', 'type', 'project', 'reference', 'payment', 'refund', 'remarks'],
            ),
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        return Inertia::render('Vendors/Form', [
            'vendor' => $this->payload($vendor),
        ]);
    }

    public function update(VendorRequest $request, Vendor $vendor, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'vendors.edit'), 403);

        $vendor->update($request->validated());

        return redirect()->route('vendors.show', $vendor)->with('success', 'Vendor updated.');
    }

    public function updateStatus(Vendor $vendor): RedirectResponse
    {
        $vendor->update([
            'status' => $vendor->isActive() ? Vendor::INACTIVE : Vendor::ACTIVE,
        ]);

        return back()->with('success', 'Vendor status updated.');
    }

    public function destroy(Request $request, Vendor $vendor, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'vendors.edit'), 403);

        $label = trim($vendor->vendor_code.' '.$vendor->name);
        $deleted = DB::transaction(function () use ($vendor): bool {
            $locked = Vendor::query()->whereKey($vendor->id)->lockForUpdate()->first();
            if ($locked === null || $locked->hasTransactions()) {
                return false;
            }

            return (bool) $locked->delete();
        });

        if (! $deleted) {
            return back()->with('error', "{$label} cannot be deleted because it has linked purchase orders, receipts, invoices, payments, notes, work orders, or expenses. Mark it inactive instead to stop new transactions while keeping its history.");
        }

        $previous = (string) parse_url(url()->previous(), PHP_URL_PATH);
        $backToList = $previous === '' || str_starts_with($previous, '/vendors/'.$vendor->id);

        return ($backToList ? redirect()->route('vendors.index') : back(fallback: route('vendors.index')))
            ->with('success', "Vendor {$label} deleted.");
    }

    public function report(Request $request, VendorBalanceService $balances, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.view'), 403);

        $vendorId = $request->integer('vendor_id') ?: null;
        $scope = $access->projectScope($request->user(), $request->integer('project_id') ?: null);
        $projectId = $scope['project_id'];
        $projectIds = $scope['project_ids'];
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $filters = Listing::filters($request);
        $query = Vendor::query()
            ->when($vendorId, fn ($query) => $query->whereKey($vendorId))
            ->orderBy('name')
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], ['name', 'vendor_code', 'gst_number']);

        return Inertia::render('Vendors/Report', [
            'filters' => [
                ...$filters,
                'vendor_id' => $vendorId,
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name']),
            'rows' => Listing::paginate($query, $request)->through(function (Vendor $vendor) use ($balances, $projectId, $from, $to, $projectIds) {
                $summary = $balances->summary($vendor, $projectId, $from, $to, $projectIds);

                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'vendor_code' => $vendor->vendor_code,
                    ...$summary,
                ];
            }),
        ]);
    }

    private function nextCode(): string
    {
        $latest = Vendor::query()->orderByDesc('id')->lockForUpdate()->value('vendor_code');
        $sequence = 1;

        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('VEN-%03d', $sequence);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'vendor_code' => $vendor->vendor_code,
            'party_type' => $vendor->party_type ?: Vendor::VENDOR,
            'name' => $vendor->name,
            'contact_person' => $vendor->contact_person,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'address' => $vendor->address,
            'city' => $vendor->city,
            'state' => $vendor->state,
            'pincode' => $vendor->pincode,
            'gst_number' => $vendor->gst_number,
            'pan_number' => $vendor->pan_number,
            'bank_name' => $vendor->bank_name,
            'account_number' => $vendor->account_number,
            'ifsc' => $vendor->ifsc,
            'opening_advance' => (string) $vendor->opening_advance,
            'opening_outstanding' => (string) $vendor->opening_outstanding,
            'status' => $vendor->status,
            'notes' => $vendor->notes,
        ];
    }
}
