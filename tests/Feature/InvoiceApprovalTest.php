<?php

namespace Tests\Feature;

use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class InvoiceApprovalTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_invoice_approval_lifecycle_and_ledger_posting(): void
    {
        $clerk = User::factory()->admin()->create();
        $approver = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($clerk, $vendor);

        $this->actingAs($clerk)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-APP-1',
            'invoice_date' => '2026-10-10',
            'invoice_amount' => 500,
            'tax_amount' => 0,
        ])->assertRedirect();

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertSame(PurchaseInvoice::APPROVAL_DRAFT, $invoice->approval_status);
        $this->assertSame('0.00', app(VendorBalanceService::class)->summary($vendor->fresh())['outstanding_payable']);

        $this->actingAs($clerk)->post(route('vendors.payments.store', $vendor), [
            'payment_date' => '2026-10-11',
            'payment_type' => 'invoice_payment',
            'payment_mode' => 'upi',
            'amount' => 100,
            'purchase_invoice_id' => $invoice->id,
        ])->assertSessionHasErrors('purchase_invoice_id');

        $this->actingAs(User::factory()->withRoles('purchase_manager')->create())
            ->post(route('projects.purchase-invoices.approve', [$project, $invoice]))
            ->assertForbidden();
        $this->actingAs($clerk)->post(route('projects.purchase-invoices.submit', [$project, $invoice]))->assertRedirect();
        $this->assertSame(PurchaseInvoice::APPROVAL_SUBMITTED, $invoice->fresh()->approval_status);

        $this->actingAs($approver)->post(route('projects.purchase-invoices.reject', [$project, $invoice]), [
            'rejection_reason' => 'Wrong amount',
        ])->assertRedirect();
        $this->assertSame(PurchaseInvoice::APPROVAL_REJECTED, $invoice->fresh()->approval_status);
        $this->assertSame('0.00', app(VendorBalanceService::class)->summary($vendor->fresh())['outstanding_payable']);

        $this->actingAs($clerk)->post(route('projects.purchase-invoices.submit', [$project, $invoice->fresh()]))->assertRedirect();
        $this->actingAs($approver)->post(route('projects.purchase-invoices.approve', [$project, $invoice->fresh()]))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(PurchaseInvoice::APPROVAL_APPROVED, $invoice->approval_status);
        $this->assertSame('500.00', app(VendorBalanceService::class)->summary($vendor->fresh())['outstanding_payable']);
        $this->assertSame(1, $vendor->fresh()->invoices()->count());
    }
}
