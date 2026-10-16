<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorRequest;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\VendorBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function index(): Response
    {
        $vendors = Vendor::query()->orderBy('name')->get()->map(fn (Vendor $vendor) => [
            'id' => $vendor->id,
            'vendor_code' => $vendor->vendor_code,
            'name' => $vendor->name,
            'phone' => $vendor->phone,
            'gst_number' => $vendor->gst_number,
            'status' => $vendor->status,
        ]);

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Vendors/Form', [
            'vendor' => null,
        ]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
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

        $summary = $balances->summary($vendor);

        return Inertia::render('Vendors/Show', [
            'vendor' => $this->payload($vendor),
            'summary' => $summary,
            'tab' => $request->string('tab')->toString() ?: 'overview',
            'projects' => Project::query()->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'purchaseOrders' => $vendor->purchaseOrders()->with('project')->latest('po_date')->get()->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'project_id' => $order->project_id,
                'project_name' => $order->project?->project_name,
                'po_number' => $order->po_number,
                'po_date' => $order->po_date?->toDateString(),
                'status' => $order->status,
                'grand_total' => (string) $order->grand_total,
            ]),
            'invoices' => $vendor->invoices()->with(['project', 'purchaseOrder'])->latest('invoice_date')->get()->map(fn (PurchaseInvoice $invoice) => [
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
            'payments' => $vendor->payments()->with('project')->latest('payment_date')->get(),
            'advances' => $vendor->payments()->where('payment_type', 'advance')->latest('payment_date')->get(),
            'refunds' => [
                'recoveries' => $vendor->recoveries()->latest()->get(),
                'received' => $vendor->refunds()->latest('refund_date')->get(),
            ],
            'transactions' => $balances->transactions($vendor),
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        return Inertia::render('Vendors/Form', [
            'vendor' => $this->payload($vendor),
        ]);
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
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

    public function destroy(Vendor $vendor): RedirectResponse
    {
        if ($vendor->hasTransactions()) {
            return back()->with('error', 'This vendor cannot be deleted because transactions exist.');
        }

        $vendor->delete();

        return redirect()->route('vendors.index')->with('success', 'Vendor deleted.');
    }

    public function report(Request $request, VendorBalanceService $balances, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.view'), 403);

        $vendorId = $request->integer('vendor_id') ?: null;
        $projectId = $request->integer('project_id') ?: null;
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $vendors = Vendor::query()
            ->when($vendorId, fn ($query) => $query->whereKey($vendorId))
            ->orderBy('name')
            ->get();

        return Inertia::render('Vendors/Report', [
            'filters' => [
                'vendor_id' => $vendorId,
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'projects' => Project::query()->orderBy('project_name')->get(['id', 'project_name']),
            'rows' => $vendors->map(function (Vendor $vendor) use ($balances, $projectId, $from, $to) {
                $summary = $balances->summary($vendor, $projectId, $from, $to);

                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'vendor_code' => $vendor->vendor_code,
                    ...$summary,
                ];
            })->values(),
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
