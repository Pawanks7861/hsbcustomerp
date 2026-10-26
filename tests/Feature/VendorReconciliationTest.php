<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\VendorLedgerService;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class VendorReconciliationTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_invoice_payment_and_approved_debit_note_reconcile(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($user, $vendor, '10000.00');
        $project = $invoice->project;
        $invoice->update(['invoice_date' => '2026-10-01']);

        VendorPayment::query()->create([
            'vendor_id' => $vendor->id,
            'project_id' => $project->id,
            'payment_date' => '2026-10-05',
            'payment_type' => VendorPayment::TYPE_INVOICE,
            'payment_mode' => 'bank_transfer',
            'amount' => 3000,
            'created_by' => $user->id,
        ]);

        DebitNote::query()->create([
            'number' => 'DN-2026-001',
            'date' => '2026-10-08',
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
            'reason' => 'Short supply',
            'status' => DocumentStatus::APPROVED,
            'subtotal' => 500,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 500,
            'created_by' => $user->id,
            'approved_by' => $user->id,
        ]);

        $result = app(VendorLedgerService::class)->reconcile($vendor->fresh());

        $this->assertTrue($result['reconciled']);
        $this->assertSame('6500.00', $result['payable_basis_closing']);
        $this->assertSame('6500.00', $result['outstanding_payable']);
        $this->assertSame('0.00', $result['difference']);
    }

    public function test_draft_debit_note_does_not_affect_reconciliation(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($user, $vendor, '5000.00');
        $project = $invoice->project;

        DebitNote::query()->create([
            'number' => 'DN-DRAFT-001',
            'date' => '2026-10-08',
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
            'reason' => 'Pending',
            'status' => DocumentStatus::DRAFT,
            'subtotal' => 1000,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 1000,
            'created_by' => $user->id,
        ]);

        $result = app(VendorLedgerService::class)->reconcile($vendor->fresh());

        $this->assertTrue($result['reconciled']);
        $this->assertSame('5000.00', $result['outstanding_payable']);
        $this->assertSame('0.00', $result['payable_components']['notes_debits']);
    }

    public function test_advance_payment_does_not_create_false_difference(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($user, $vendor, '10000.00');
        $project = $invoice->project;

        VendorPayment::query()->create([
            'vendor_id' => $vendor->id,
            'project_id' => $project->id,
            'payment_date' => '2026-10-05',
            'payment_type' => VendorPayment::TYPE_ADVANCE,
            'payment_mode' => 'bank_transfer',
            'amount' => 4000,
            'created_by' => $user->id,
        ]);

        $result = app(VendorLedgerService::class)->reconcile($vendor->fresh());

        $this->assertTrue($result['reconciled']);
        $this->assertSame('10000.00', $result['payable_basis_closing']);
        $this->assertSame('10000.00', $result['outstanding_payable']);
        $this->assertSame('4000.00', $result['advance_paid']);
        $this->assertSame('0.00', $result['difference']);
    }
}
