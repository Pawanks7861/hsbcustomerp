<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_approved_credit_note_reduces_outstanding(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $invoice = $this->recordedInvoice($user, $vendor, '5000.00');
        $project = $invoice->project;

        $this->actingAs($user)->post(route('projects.credit-notes.store', $project), [
            'date' => '2026-10-12',
            'vendor_id' => $vendor->id,
            'vendor_reference' => 'CN-VENDOR-1',
            'reason' => 'Rate difference',
            'subtotal' => 800,
            'tax_amount' => 0,
            'round_off' => 0,
        ])->assertRedirect();

        $note = CreditNote::query()->firstOrFail();
        $this->actingAs($user)->post(route('projects.credit-notes.submit', [$project, $note]))->assertRedirect();
        $this->actingAs($user)->post(route('projects.credit-notes.approve', [$project, $note->fresh()]))->assertRedirect();

        $summary = app(VendorBalanceService::class)->summary($vendor->fresh());
        $this->assertSame('800.00', $summary['credit_notes_total']);
        $this->assertSame('4200.00', $summary['outstanding_payable']);
    }
}
