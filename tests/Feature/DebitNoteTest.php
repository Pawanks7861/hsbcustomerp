<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorBalanceService;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class DebitNoteTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_approved_debit_note_reduces_outstanding_and_draft_is_ignored(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $invoice = $this->recordedInvoice($user, $vendor, '10000.00');
        $project = $invoice->project;

        $balances = app(VendorBalanceService::class);
        $this->assertSame('10000.00', $balances->summary($vendor->fresh())['outstanding_payable']);

        $this->actingAs($user)->post(route('projects.debit-notes.store', $project), [
            'date' => '2026-10-11',
            'vendor_id' => $vendor->id,
            'purchase_invoice_id' => $invoice->id,
            'reason' => 'Damaged goods',
            'subtotal' => 1500,
            'tax_amount' => 0,
            'round_off' => 0,
        ])->assertRedirect();

        $note = DebitNote::query()->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $note->status);
        $this->assertSame('10000.00', $balances->summary($vendor->fresh())['outstanding_payable']);

        $this->actingAs($user)->post(route('projects.debit-notes.submit', [$project, $note]))->assertRedirect();
        $this->actingAs($user)->post(route('projects.debit-notes.approve', [$project, $note->fresh()]))->assertRedirect();

        $summary = $balances->summary($vendor->fresh());
        $this->assertSame('1500.00', $summary['debit_notes_total']);
        $this->assertSame('0.00', $summary['credit_notes_total']);
        $this->assertSame('8500.00', $summary['outstanding_payable']);
    }
}
