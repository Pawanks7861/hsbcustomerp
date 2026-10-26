<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorRecovery;
use App\Services\VendorBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VendorStoreInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_master_po_snapshot_and_delete_protection(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('vendors.store'), $this->vendorProfile([
            'name' => 'ABC Cement',
            'phone' => '9999999999',
            'gst_number' => '24AAAAA0000A1Z5',
        ]))->assertRedirect();

        $vendor = Vendor::query()->firstOrFail();
        $this->assertSame('VEN-001', $vendor->vendor_code);

        $this->actingAs($user)->post(route('vendors.store'), [
            'name' => 'Second Vendor',
        ])->assertSessionHasErrors();
        $this->actingAs($user)->post(route('vendors.store'), $this->vendorProfile([
            'name' => 'Second Vendor',
            'email' => 'second@example.com',
            'gst_number' => '27AAAAA0000A1Z5',
        ]))->assertRedirect();
        $this->assertSame('VEN-002', Vendor::query()->orderByDesc('id')->first()->vendor_code);

        $this->actingAs($user)->put(route('vendors.update', $vendor), [
            'name' => 'ABC Cement Supplier',
        ])->assertRedirect();
        $this->assertSame('ABC Cement Supplier', $vendor->fresh()->name);

        $inactive = Vendor::factory()->create(['status' => Vendor::INACTIVE]);
        [$project, $order] = $this->approvedOrder($user, $vendor, false);

        $this->actingAs($user)
            ->put(route('projects.purchase-orders.update', [$project, $order]), [
                'po_date' => '2026-10-09',
                'vendor_id' => $inactive->id,
                'gst_percentage' => 0,
            ])->assertSessionHasErrors('vendor_id');

        $vendor->update(['name' => 'Renamed Later', 'address' => 'New address']);
        $order->refresh();
        $this->assertSame('ABC Cement Supplier', $order->vendor_name_snapshot);
        $this->assertSame('ABC Cement Supplier', $order->supplier_name);
        $this->assertNotSame('New address', $order->vendor_address_snapshot);

        $this->actingAs($user)->delete(route('vendors.destroy', $vendor))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($vendor->fresh());
    }

    public function test_store_receipts_support_po_linked_and_manual_entries(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($user, $vendor);
        $other = Project::factory()->create();
        $line = $order->items()->first();

        $this->actingAs($user)->post(route('projects.store.store', $project), [
            'received_by' => 'Site Engineer',
            'items' => [[
                'description' => 'Missing date',
                'quantity_received' => 1,
            ]],
        ])->assertSessionHasErrors('receipt_date');

        $this->actingAs($user)->post(route('projects.store.store', $project), [
            'receipt_date' => '2026-10-09',
            'purchase_order_id' => $order->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'received_by' => 'Site Engineer',
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'description' => 'Changed description',
                'unit' => 'Ton',
                'quantity_received' => 2,
            ]],
            'images' => [UploadedFile::fake()->image('site.jpg')],
        ])->assertRedirect();

        $receipt = $project->materialReceipts()->firstOrFail();
        $this->assertSame($vendor->id, $receipt->vendor_id);
        $this->assertSame($order->id, $receipt->purchase_order_id);
        $this->assertSame($line->description, $receipt->items()->first()->description);
        $this->assertSame('2.000', (string) $receipt->items()->first()->quantity_received);
        $this->assertSame(1, $receipt->images()->count());
        $this->assertStringStartsWith('MR-', $receipt->receipt_number);

        $this->actingAs($user)
            ->get(route('projects.store.images.show', [$project, $receipt, $receipt->images()->first()]))
            ->assertOk();

        $this->actingAs($user)->post(route('projects.store.store', $other), [
            'receipt_date' => '2026-10-09',
            'purchase_order_id' => $order->id,
            'received_by' => 'Site Engineer',
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'quantity_received' => 1,
            ]],
        ])->assertNotFound();

        $this->actingAs($user)->post(route('projects.store.store', $project), [
            'receipt_date' => '2026-10-10',
            'received_by' => 'Store Keeper',
            'items' => [[
                'description' => 'Loose sand',
                'unit' => 'CUM',
                'quantity_received' => '3.5',
            ]],
        ])->assertRedirect();

        $manual = $project->materialReceipts()->whereNull('purchase_order_id')->firstOrFail();
        $this->assertNull($manual->vendor_id);
        $this->assertSame('Loose sand', $manual->items()->first()->description);

        $this->actingAs($user)
            ->get(route('projects.store.show', [$project, $manual]))
            ->assertInertia(fn ($page) => $page
                ->where('receipt.is_grn', false)
                ->where('receipt.can_edit', true)
                ->where('receipt.can_delete', true));

        $manual->update(['document_kind' => MaterialReceipt::KIND_GRN, 'status' => MaterialReceipt::STATUS_POSTED]);

        $this->actingAs($user)
            ->get(route('projects.store.show', [$project, $manual]))
            ->assertInertia(fn ($page) => $page
                ->where('receipt.is_grn', true)
                ->where('receipt.can_edit', false)
                ->where('receipt.can_delete', false));
        $this->actingAs($user)->get(route('projects.store.edit', [$project, $manual]))->assertNotFound();
    }

    public function test_purchase_invoices_follow_the_purchase_order_vendor(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        [$project, $order] = $this->approvedOrder($user, $vendor);
        $other = Project::factory()->create();

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'vendor_id' => $otherVendor->id,
            'invoice_number' => 'INV-1',
            'invoice_date' => '2026-10-09',
            'invoice_amount' => 80000,
            'tax_amount' => 0,
            'total_amount' => 1,
            'invoice_file' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertSame($project->id, $invoice->project_id);
        $this->assertSame($order->id, $invoice->purchase_order_id);
        $this->assertSame($vendor->id, $invoice->vendor_id);
        $this->assertSame('80000.00', (string) $invoice->total_amount);
        $this->assertNotNull($invoice->invoice_file_path);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-2',
            'invoice_date' => '2026-10-10',
            'invoice_amount' => 100,
            'tax_amount' => 18,
        ])->assertRedirect();
        $this->assertSame(2, $order->invoices()->count());
        $this->assertSame('118.00', (string) PurchaseInvoice::query()->where('invoice_number', 'INV-2')->first()->total_amount);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $other), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-3',
            'invoice_date' => '2026-10-11',
            'invoice_amount' => 10,
        ])->assertNotFound();

        $this->actingAs($user)
            ->get(route('projects.purchase-invoices.attachment', [$project, $invoice]))
            ->assertOk();

        $order->update(['vendor_id' => null]);
        $legacyVendor = Vendor::factory()->create();

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-LEGACY',
            'invoice_date' => '2026-10-12',
            'invoice_amount' => 50,
        ])->assertSessionHasErrors('vendor_id');

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'vendor_id' => $legacyVendor->id,
            'invoice_number' => 'INV-LEGACY',
            'invoice_date' => '2026-10-12',
            'invoice_amount' => 50,
        ])->assertRedirect();

        $this->assertSame($legacyVendor->id, $order->fresh()->vendor_id);
        $this->assertSame($legacyVendor->id, PurchaseInvoice::query()->where('invoice_number', 'INV-LEGACY')->firstOrFail()->vendor_id);
    }

    public function test_payments_advances_adjustments_refunds_and_report(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_advance' => 0, 'opening_outstanding' => 0]);
        [$project, $order] = $this->approvedOrder($user, $vendor);
        $order->update(['grand_total' => 800000, 'status' => 'approved']);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'BILL-1',
            'invoice_date' => '2026-10-09',
            'invoice_amount' => 750000,
            'tax_amount' => 0,
        ])->assertRedirect();
        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->markInvoiceApproved($invoice, $user);

        $this->actingAs($user)->post(route('vendors.payments.store', $vendor), [
            'payment_date' => '2026-10-01',
            'payment_type' => VendorPayment::TYPE_ADVANCE,
            'payment_mode' => 'bank_transfer',
            'amount' => 200000,
            'project_id' => $project->id,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('vendors.payments.store', $vendor), [
            'payment_date' => '2026-10-11',
            'payment_type' => VendorPayment::TYPE_INVOICE,
            'payment_mode' => 'upi',
            'amount' => 800000,
            'purchase_invoice_id' => $invoice->id,
        ])->assertSessionHasErrors('amount');

        $this->actingAs($user)->post(route('vendors.payments.store', $vendor), [
            'payment_date' => '2026-10-11',
            'payment_type' => VendorPayment::TYPE_INVOICE,
            'payment_mode' => 'upi',
            'amount' => 500000,
            'purchase_invoice_id' => $invoice->id,
        ])->assertRedirect();

        $payment = VendorPayment::query()->where('payment_type', VendorPayment::TYPE_INVOICE)->first();
        $this->assertSame('upi', $payment->payment_mode);
        $this->assertSame('250000.00', app(VendorBalanceService::class)->invoiceOutstanding($invoice->fresh()));

        $this->actingAs($user)->post(route('projects.purchase-invoices.advance', [$project, $invoice]), [
            'amount' => 150000,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('projects.purchase-invoices.advance', [$project, $invoice]), [
            'amount' => 60000,
        ])->assertSessionHasErrors('amount');

        $summary = app(VendorBalanceService::class)->summary($vendor->fresh());
        $this->assertSame('150000.00', $summary['advance_adjusted']);
        $this->assertSame('50000.00', $summary['available_advance']);
        $this->assertSame('100000.00', $summary['outstanding_payable']);
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $this->actingAs($user)->post(route('vendors.recoveries.store', $vendor), [
            'amount_due' => 60000,
        ])->assertSessionHasErrors('amount_due');

        $this->actingAs($user)->post(route('vendors.recoveries.store', $vendor), [
            'amount_due' => 40000,
            'project_id' => $project->id,
        ])->assertRedirect();

        $recovery = VendorRecovery::query()->firstOrFail();
        $summary = app(VendorBalanceService::class)->summary($vendor->fresh());
        $this->assertSame('10000.00', $summary['available_advance']);
        $this->assertSame('40000.00', $summary['refund_due']);

        $this->actingAs($user)->post(route('vendors.recoveries.refunds.store', [$vendor, $recovery]), [
            'refund_date' => '2026-10-12',
            'amount' => 50000,
            'refund_mode' => 'bank_transfer',
        ])->assertSessionHasErrors('amount');

        $this->actingAs($user)->post(route('vendors.recoveries.refunds.store', [$vendor, $recovery]), [
            'refund_date' => '2026-10-12',
            'amount' => 25000,
            'refund_mode' => 'bank_transfer',
        ])->assertRedirect();
        $this->assertSame('partial', $recovery->fresh()->status);
        $this->assertSame('15000.00', app(VendorBalanceService::class)->summary($vendor->fresh())['refund_due']);

        $this->actingAs($user)->post(route('vendors.recoveries.refunds.store', [$vendor, $recovery]), [
            'refund_date' => '2026-10-13',
            'amount' => 15000,
            'refund_mode' => 'cash',
        ])->assertRedirect();
        $this->assertSame('recovered', $recovery->fresh()->status);
        $closed = app(VendorBalanceService::class)->summary($vendor->fresh());
        $this->assertSame('0.00', $closed['refund_due']);
        $this->assertSame('40000.00', $closed['refund_received']);

        $this->actingAs($user)->get(route('vendors.report', [
            'vendor_id' => $vendor->id,
            'project_id' => $project->id,
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Vendors/Report')
            ->where('rows.data.0.vendor_code', $vendor->vendor_code)
            ->where('rows.data.0.advance_paid', '200000.00')
            ->where('rows.data.0.invoice_payments', '500000.00')
            ->where('rows.data.0.refund_received', '40000.00')
            ->has('rows.data', 1)
        );

        $this->actingAs($user)->get(route('vendors.report', [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ]))->assertInertia(fn ($page) => $page
            ->where('rows.data.0.advance_paid', '0.00')
            ->where('rows.data.0.total_invoice_value', '0.00')
        );
    }

    /**
     * @return array{0: Project, 1: PurchaseOrder}
     */
    private function approvedOrder(User $user, Vendor $vendor, bool $approve = true): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'quantity' => 100,
            'rate' => 100,
            'unit' => 'Bag',
        ]);

        $this->actingAs($user)->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), [
            'request_date' => '2026-10-09',
            'description' => 'Cement',
            'requested_boq_quantity' => '1.000',
            'items' => [[
                'description' => 'Cement',
                'unit' => 'Bag',
                'quantity' => 2,
                'estimated_rate' => 10,
            ]],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->firstOrFail();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id]);

        $this->actingAs($user)->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), [
            'po_date' => '2026-10-09',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
        ])->assertRedirect();

        $order = PurchaseOrder::query()->latest('id')->firstOrFail();

        if ($approve) {
            $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id]);
        }

        return [$project, $order->fresh('items')];
    }
}
