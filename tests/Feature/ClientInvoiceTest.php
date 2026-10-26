<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ClientLedgerService;
use App\Services\VendorBalanceService;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class ClientInvoiceTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_client_invoice_lifecycle_calculations_payments_and_isolation(): void
    {
        $clerk = User::factory()->admin()->create();
        $approver = User::factory()->admin()->create();
        $outsider = User::factory()->create(['project_access' => 'selected']);
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $item = $this->boqItem($project, 10, 100);

        $this->actingAs($clerk)->post(route('projects.client-invoices.store', $project), [
            'client_id' => $client->id,
            'invoice_date' => '2026-10-20',
            'due_date' => '2026-11-19',
            'gst_percentage' => '18',
            'round_off' => '0',
            'items' => [[
                'boq_item_id' => $item->id,
                'description' => $item->description,
                'unit' => 'Cum',
                'quantity' => 4,
                'rate' => 100,
            ]],
        ])->assertRedirect();

        $invoice = ClientInvoice::query()->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $invoice->approval_status);
        $this->assertSame('CIN-2026-001', $invoice->invoice_number);
        $this->assertSame('400.00', (string) $invoice->subtotal);
        $this->assertSame('72.00', (string) $invoice->gst_amount);
        $this->assertSame('472.00', (string) $invoice->grand_total);
        $this->assertSame('0.00', app(ClientLedgerService::class)->summary($client)['outstanding']);
        $draftPdf = $this->actingAs($clerk)->get(route('projects.client-invoices.pdf', [$project, $invoice]));
        $draftPdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $draftPdf->headers->get('content-type'));

        $this->actingAs($clerk)->post(route('projects.client-invoices.store', $project), [
            'client_id' => $client->id,
            'invoice_date' => '2026-10-20',
            'gst_percentage' => '0',
            'items' => [[
                'boq_item_id' => $item->id,
                'description' => $item->description,
                'unit' => 'Cum',
                'quantity' => 7,
                'rate' => 100,
            ]],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($clerk)->post(route('projects.client-invoices.payments.store', [$project, $invoice]), [
            'payment_date' => '2026-10-21',
            'payment_mode' => 'upi',
            'amount' => 10,
        ])->assertSessionHasErrors('client_invoice_id');

        $this->actingAs(User::factory()->withRoles('project_manager')->create())
            ->post(route('projects.client-invoices.approve', [$project, $invoice]))
            ->assertForbidden();
        $this->actingAs($clerk)->post(route('projects.client-invoices.submit', [$project, $invoice]))->assertRedirect();
        $this->actingAs($approver)->post(route('projects.client-invoices.reject', [$project, $invoice]), [
            'rejection_reason' => 'Check quantity',
        ])->assertRedirect();
        $this->assertSame(DocumentStatus::REJECTED, $invoice->fresh()->approval_status);

        $this->actingAs($clerk)->put(route('projects.client-invoices.update', [$project, $invoice]), [
            'client_id' => $client->id,
            'invoice_date' => '2026-10-20',
            'due_date' => '2026-11-19',
            'gst_percentage' => '18',
            'round_off' => '-0.20',
            'items' => [[
                'boq_item_id' => $item->id,
                'description' => $item->description,
                'unit' => 'Cum',
                'quantity' => 4,
                'rate' => 100,
            ]],
        ])->assertRedirect();

        $this->actingAs($clerk)->post(route('projects.client-invoices.submit', [$project, $invoice->fresh()]))->assertRedirect();
        $this->actingAs($approver)->post(route('projects.client-invoices.approve', [$project, $invoice->fresh()]))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(DocumentStatus::APPROVED, $invoice->approval_status);
        $this->assertSame('471.80', (string) $invoice->grand_total);
        $this->assertSame('471.80', app(ClientLedgerService::class)->summary($client->fresh())['outstanding']);

        $pdf = $this->actingAs($clerk)->get(route('projects.client-invoices.pdf', [$project, $invoice]));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->actingAs($clerk)->get(route('projects.client-invoices.pdf.download', [$project, $invoice]))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($clerk)->post(route('projects.client-invoices.payments.store', [$project, $invoice]), [
            'payment_date' => '2026-10-22',
            'payment_mode' => 'bank_transfer',
            'amount' => 200,
            'reference_number' => 'UTR-1',
        ])->assertRedirect();
        $this->actingAs($clerk)->post(route('projects.client-invoices.payments.store', [$project, $invoice]), [
            'payment_date' => '2026-10-22',
            'payment_mode' => 'bank_transfer',
            'amount' => 200,
            'reference_number' => 'UTR-1',
        ])->assertSessionHasErrors('reference_number');
        $this->actingAs($clerk)->post(route('projects.client-invoices.payments.store', [$project, $invoice]), [
            'payment_date' => '2026-10-23',
            'payment_mode' => 'cash',
            'amount' => 400,
            'reference_number' => 'CASH-2',
        ])->assertSessionHasErrors('amount');
        $this->actingAs($clerk)->post(route('projects.client-invoices.payments.store', [$project, $invoice]), [
            'payment_date' => '2026-10-23',
            'payment_mode' => 'cash',
            'amount' => 271.80,
            'reference_number' => 'CASH-2',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(ClientInvoice::PAYMENT_PAID, $invoice->payment_status);
        $this->assertSame('0.00', $invoice->outstanding());
        $this->assertSame(2, ClientPayment::query()->count());
        $this->assertSame('471.80', app(ClientLedgerService::class)->summary($client->fresh())['received']);
        $this->assertSame('0.00', app(ClientLedgerService::class)->summary($otherClient)['outstanding']);

        $this->actingAs($clerk)->post(route('projects.client-invoices.store', $project), [
            'client_id' => $client->id,
            'invoice_date' => '2026-10-24',
            'gst_percentage' => '0',
            'items' => [[
                'description' => 'Supervision',
                'unit' => 'Job',
                'quantity' => 1,
                'rate' => 50,
            ]],
        ])->assertRedirect();
        $second = ClientInvoice::query()->where('invoice_number', 'CIN-2026-002')->firstOrFail();
        $this->assertNull($second->items()->first()->boq_item_id);

        $this->actingAs($outsider)->get(route('projects.client-invoices.show', [$project, $invoice]))->assertNotFound();
        $this->actingAs($clerk)->get(route('projects.client-invoices.index', $otherProject))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('invoices.data', 0));

        $denied = User::factory()->create();
        $denied->permissionOverrides()->create([
            'permission' => 'client_invoices.view',
            'effect' => 'deny',
        ]);
        $this->actingAs($denied)->get(route('client-invoices.index'))->assertForbidden();

        $this->actingAs($approver)->get(route('reports.show', ['report' => 'client-invoice-register']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Show')->has('rows'));
        $this->actingAs($approver)->get(route('reports.export.excel', ['report' => 'client-invoice-register']))
            ->assertOk();
        $this->actingAs($approver)->get(route('reports.show', ['report' => 'client-outstanding']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 0));
        $this->actingAs($approver)->get(route('clients.ledger', $client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Clients/Ledger')->has('entries.data', 3));
    }

    public function test_client_invoices_do_not_affect_vendor_payables(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($admin, $vendor);
        $this->actingAs($admin)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'VEN-INV-1',
            'invoice_date' => '2026-10-10',
            'invoice_amount' => 500,
            'tax_amount' => 0,
        ])->assertRedirect();
        $purchaseInvoice = PurchaseInvoice::query()->firstOrFail();
        $this->markInvoiceApproved($purchaseInvoice, $admin);

        $client = Client::factory()->create();
        $this->actingAs($admin)->post(route('projects.client-invoices.store', $project), [
            'client_id' => $client->id,
            'invoice_date' => '2026-10-20',
            'gst_percentage' => '0',
            'items' => [['description' => 'Billable work', 'unit' => 'Job', 'quantity' => 1, 'rate' => 900]],
        ])->assertRedirect();
        $clientInvoice = ClientInvoice::query()->firstOrFail();
        $this->actingAs($admin)->post(route('projects.client-invoices.submit', [$project, $clientInvoice]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.client-invoices.approve', [$project, $clientInvoice->fresh()]))->assertRedirect();

        $this->assertSame('500.00', app(VendorBalanceService::class)->summary($vendor->fresh())['outstanding_payable']);
        $this->assertSame('900.00', app(ClientLedgerService::class)->summary($client->fresh())['outstanding']);
        $this->assertSame(0, $vendor->invoices()->where('invoice_number', $clientInvoice->invoice_number)->count());
    }

    public function test_incomplete_client_and_invoice_validation(): void
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();

        $this->actingAs($user)->post(route('clients.store'), [
            'name' => 'Incomplete',
        ])->assertSessionHasErrors(['contact_person', 'phone', 'email']);

        $client = Client::factory()->create();
        $this->actingAs($user)->post(route('projects.client-invoices.store', $project), [
            'client_id' => $client->id,
            'invoice_date' => '2026-10-20',
            'gst_percentage' => '18',
            'items' => [['description' => 'Bad', 'unit' => 'Nos', 'quantity' => -1, 'rate' => 10]],
        ])->assertSessionHasErrors('items.0.quantity');
    }

    private function boqItem(Project $project, string $quantity, string $rate): BoqItem
    {
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 18]);

        return BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '1.1',
            'description' => 'Excavation',
            'quantity' => $quantity,
            'rate' => $rate,
            'unit' => 'Cum',
        ]);
    }
}
