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

class DebitNoteQuantityTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_item_lines_calculate_totals_and_amount_only_notes_still_work(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $invoice = $this->recordedInvoice($user, $vendor, '10000.00');
        $project = $invoice->project;
        $order = $invoice->purchaseOrder;
        $line = $order->items()->firstOrFail();

        $this->actingAs($user)->post(route('projects.debit-notes.store', $project), [
            'date' => '2026-10-11',
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $order->id,
            'purchase_invoice_id' => $invoice->id,
            'reason' => 'Short supply',
            'tax_amount' => 0,
            'round_off' => 0,
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => 2,
                'rate' => 150,
            ]],
        ])->assertRedirect();

        $note = DebitNote::query()->firstOrFail();
        $this->assertSame('300.00', (string) $note->subtotal);
        $this->assertSame('300.00', (string) $note->total_amount);
        $this->assertSame(1, $note->items()->count());
        $this->assertSame('2.000', (string) $note->items()->first()->quantity);

        $this->actingAs($user)->post(route('projects.debit-notes.submit', [$project, $note]))->assertRedirect();
        $this->actingAs($user)->post(route('projects.debit-notes.approve', [$project, $note->fresh()]))->assertRedirect();
        $this->assertSame('9700.00', app(VendorBalanceService::class)->summary($vendor->fresh())['outstanding_payable']);

        $this->actingAs($user)->post(route('projects.debit-notes.store', $project), [
            'date' => '2026-10-12',
            'vendor_id' => $vendor->id,
            'reason' => 'Rate adjustment',
            'subtotal' => 200,
            'tax_amount' => 0,
            'round_off' => 0,
        ])->assertRedirect();

        $amountOnly = DebitNote::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $amountOnly->items()->count());
        $this->assertSame('200.00', (string) $amountOnly->total_amount);
        $this->assertSame(DocumentStatus::DRAFT, $amountOnly->status);
    }

    public function test_negative_quantity_is_rejected(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $invoice = $this->recordedInvoice($user, $vendor, '10000.00');

        $this->actingAs($user)->post(route('projects.debit-notes.store', $invoice->project), [
            'date' => '2026-10-11',
            'vendor_id' => $vendor->id,
            'reason' => 'Invalid qty',
            'items' => [[
                'description' => 'Cement',
                'quantity' => -1,
                'rate' => 10,
            ]],
        ])->assertSessionHasErrors('items.0.quantity');
    }
}
