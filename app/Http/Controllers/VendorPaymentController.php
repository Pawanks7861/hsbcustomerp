<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\VendorBalanceService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VendorPaymentController extends Controller
{
    public function create(Request $request, Vendor $vendor, VendorBalanceService $balances): Response
    {
        $invoice = $this->invoiceFromRequest($request, $vendor);

        return Inertia::render('Vendors/PaymentForm', [
            'vendor' => ['id' => $vendor->id, 'name' => $vendor->name, 'vendor_code' => $vendor->vendor_code],
            'projects' => Project::query()->orderBy('project_name')->get(['id', 'project_name']),
            'purchaseOrders' => $vendor->purchaseOrders()->orderByDesc('po_date')->get(['id', 'po_number', 'project_id', 'grand_total']),
            'invoices' => $vendor->invoices()->where('approval_status', PurchaseInvoice::APPROVAL_APPROVED)->with('purchaseOrder:id,po_number')->orderByDesc('invoice_date')->get(),
            'invoice' => $invoice ? $this->invoiceCard($invoice, $balances) : null,
            'preset' => [
                'payment_type' => $request->string('payment_type')->toString() ?: ($invoice ? VendorPayment::TYPE_INVOICE : VendorPayment::TYPE_ADVANCE),
                'project_id' => $invoice?->project_id,
                'purchase_order_id' => $invoice?->purchase_order_id,
                'purchase_invoice_id' => $invoice?->id,
            ],
        ]);
    }

    public function store(Request $request, Vendor $vendor, VendorBalanceService $balances): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'purchase_invoice_id' => ['nullable', 'integer', 'exists:purchase_invoices,id'],
            'payment_date' => ['required', 'date'],
            'payment_type' => ['required', Rule::in([VendorPayment::TYPE_ADVANCE, VendorPayment::TYPE_INVOICE, VendorPayment::TYPE_OTHER])],
            'payment_mode' => ['required', Rule::in(VendorPayment::MODES)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = Money::scale($validated['amount'], 2);
        $invoice = null;

        if ($validated['payment_type'] === VendorPayment::TYPE_INVOICE) {
            $invoice = PurchaseInvoice::query()->find($validated['purchase_invoice_id'] ?? null);

            if (! $invoice || (int) $invoice->vendor_id !== (int) $vendor->id) {
                throw ValidationException::withMessages([
                    'purchase_invoice_id' => 'Choose an invoice for this vendor.',
                ]);
            }

            if (! $invoice->isApproved()) {
                throw ValidationException::withMessages([
                    'purchase_invoice_id' => 'Payments can only be recorded against an approved invoice.',
                ]);
            }

            if (bccomp($amount, $balances->invoiceOutstanding($invoice), 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the invoice outstanding.',
                ]);
            }

            $validated['project_id'] = $invoice->project_id;
            $validated['purchase_order_id'] = $invoice->purchase_order_id;
        }

        if (! empty($validated['purchase_order_id'])) {
            $order = PurchaseOrder::query()->findOrFail($validated['purchase_order_id']);
            abort_unless((int) $order->vendor_id === (int) $vendor->id, 422);
            if (! empty($validated['project_id'])) {
                abort_unless((int) $order->project_id === (int) $validated['project_id'], 422);
            }
        }

        DB::transaction(function () use ($vendor, $validated, $amount, $invoice, $request, $balances): void {
            VendorPayment::query()->create([
                ...$validated,
                'vendor_id' => $vendor->id,
                'amount' => $amount,
                'created_by' => $request->user()->id,
            ]);

            if ($invoice) {
                $balances->refreshInvoice($invoice);
            }
        });

        return redirect()->route('vendors.show', [$vendor, 'tab' => 'payments'])->with('success', 'Payment recorded.');
    }

    public function destroy(Vendor $vendor, VendorPayment $payment): RedirectResponse
    {
        abort_unless($payment->vendor_id === $vendor->id, 404);

        if ($payment->adjustments()->exists() || $payment->recoveries()->exists()) {
            return back()->with('error', 'This payment cannot be deleted because it is used in an adjustment or recovery.');
        }

        $invoice = $payment->invoice;
        $payment->delete();

        if ($invoice) {
            app(VendorBalanceService::class)->refreshInvoice($invoice);
        }

        return back()->with('success', 'Payment deleted.');
    }

    private function invoiceFromRequest(Request $request, Vendor $vendor): ?PurchaseInvoice
    {
        $id = $request->integer('purchase_invoice_id');

        if (! $id) {
            return null;
        }

        $invoice = PurchaseInvoice::query()->findOrFail($id);
        abort_unless((int) $invoice->vendor_id === (int) $vendor->id, 404);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceCard(PurchaseInvoice $invoice, VendorBalanceService $balances): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total_amount' => (string) $invoice->total_amount,
            'paid' => $balances->directInvoicePayments($invoice),
            'advance_applied' => $balances->advanceApplied($invoice),
            'outstanding' => $balances->invoiceOutstanding($invoice),
        ];
    }
}
