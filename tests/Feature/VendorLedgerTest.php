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

class VendorLedgerTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_ledger_closing_matches_payable_movements(): void
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

        $statement = app(VendorLedgerService::class)->statement($vendor->fresh());
        $this->assertSame('6500.00', $statement['closing']);

        $this->actingAs($user)->get(route('vendors.ledger', $vendor))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Vendors/Ledger')
            ->where('closing', '6500.00')
        );
    }

    public function test_advance_payment_does_not_reduce_ledger_payable(): void
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
            'payment_type' => VendorPayment::TYPE_ADVANCE,
            'payment_mode' => 'bank_transfer',
            'amount' => 3000,
            'created_by' => $user->id,
        ]);

        $statement = app(VendorLedgerService::class)->statement($vendor->fresh());

        $this->assertSame('10000.00', $statement['closing']);
        $this->assertSame('0.00', $statement['total_debit']);
    }

    public function test_invoice_round_off_appears_once_in_ledger_credit(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($user, $vendor, '9999.50');
        $invoice->update([
            'invoice_amount' => '9999.47',
            'round_off' => '0.03',
            'total_amount' => '9999.50',
        ]);

        $statement = app(VendorLedgerService::class)->statement($vendor->fresh());
        $invoiceCredits = collect($statement['entries'])
            ->filter(fn (array $entry) => $entry['type'] === 'Purchase Invoice' && $entry['credit'] !== null);

        $this->assertCount(1, $invoiceCredits);
        $this->assertSame('9999.50', $invoiceCredits->first()['credit']);
        $this->assertSame('9999.50', $statement['closing']);
    }
}
