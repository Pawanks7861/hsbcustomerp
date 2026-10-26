<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\VendorBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_invoices_bill_po_lines_without_changing_the_order(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order, $cement, $sand] = $this->order($user);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-15',
            'invoice_date' => '2026-10-12',
            'items' => [
                ['purchase_order_item_id' => $cement->id, 'quantity' => 15],
                ['purchase_order_item_id' => $sand->id, 'quantity' => ''],
            ],
        ])->assertRedirect();

        $first = PurchaseInvoice::query()->where('invoice_number', 'INV-15')->firstOrFail();
        $this->assertSame('6000.00', (string) $first->invoice_amount);
        $this->assertSame('1080.00', (string) $first->tax_amount);
        $this->assertSame('7080.00', (string) $first->total_amount);
        $this->assertSame('15.000', (string) $first->items()->first()->quantity);
        $this->assertSame('0.000', (string) $first->items()->first()->previously_invoiced);
        $this->assertSame('6000.00', (string) $first->items()->first()->amount);
        $this->assertSame(1, $first->items()->count());
        $this->assertSame('20.000', (string) $cement->fresh()->quantity);

        $this->actingAs($user)->put(route('projects.purchase-invoices.update', [$project, $first]), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-15',
            'invoice_date' => '2026-10-12',
            'items' => [
                ['purchase_order_item_id' => $cement->id, 'quantity' => 15],
            ],
        ])->assertRedirect();
        $this->assertSame('15.000', (string) $first->fresh()->items()->first()->quantity);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-5',
            'invoice_date' => '2026-10-13',
            'items' => [
                ['purchase_order_item_id' => $cement->id, 'quantity' => 5],
                ['purchase_order_item_id' => $sand->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $second = PurchaseInvoice::query()->where('invoice_number', 'INV-5')->firstOrFail();
        $cementLine = $second->items()->where('purchase_order_item_id', $cement->id)->firstOrFail();
        $sandLine = $second->items()->where('purchase_order_item_id', $sand->id)->firstOrFail();
        $this->assertSame('15.000', (string) $cementLine->previously_invoiced);
        $this->assertSame('5.000', (string) $cementLine->quantity);
        $this->assertSame('2000.00', (string) $cementLine->taxable_amount);
        $this->assertSame('100.00', (string) $sandLine->taxable_amount);
        $this->assertSame('20.000', (string) $cement->fresh()->quantity);
        $this->assertSame('8.000', (string) $sand->fresh()->quantity);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-OVER',
            'invoice_date' => '2026-10-14',
            'items' => [
                ['purchase_order_item_id' => $cement->id, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-DUP',
            'invoice_date' => '2026-10-14',
            'items' => [
                ['purchase_order_item_id' => $sand->id, 'quantity' => 1],
                ['purchase_order_item_id' => $sand->id, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('items.1.purchase_order_item_id');

        $this->markInvoiceApproved($first, $user);
        $this->actingAs($user)->post(route('vendors.payments.store', $order->vendor), [
            'payment_date' => '2026-10-14',
            'payment_type' => VendorPayment::TYPE_INVOICE,
            'payment_mode' => 'bank_transfer',
            'amount' => 1000,
            'purchase_invoice_id' => $first->id,
        ])->assertRedirect();

        $this->assertSame('6080.00', app(VendorBalanceService::class)->invoiceOutstanding($first->fresh()));
        $this->assertSame('1000.00', app(VendorBalanceService::class)->summary($order->vendor->fresh())['invoice_payments']);

        $this->actingAs($user)
            ->get(route('projects.purchase-invoices.show', [$project, $second]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.po_number', $order->po_number)
                ->where('invoice.items.0.po_quantity', '20.000')
                ->where('invoice.items.0.quantity', '5.000')
            );

        $this->actingAs($user)
            ->get(route('projects.purchase-invoices.pdf', [$project, $first]))
            ->assertOk();
    }

    public function test_invoice_round_off_updates_the_final_total(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order, $cement] = $this->order($user);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-ROUND',
            'invoice_date' => '2026-10-12',
            'tax_percentage' => '18',
            'round_off_mode' => 'manual',
            'round_off' => '-0.40',
            'items' => [['purchase_order_item_id' => $cement->id, 'quantity' => 1]],
        ])->assertRedirect();

        $invoice = PurchaseInvoice::query()->where('invoice_number', 'INV-ROUND')->firstOrFail();
        $this->assertSame('400.00', (string) $invoice->invoice_amount);
        $this->assertSame('72.00', (string) $invoice->tax_amount);
        $this->assertSame('-0.40', (string) $invoice->round_off);
        $this->assertSame('471.60', (string) $invoice->total_amount);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-AUTO',
            'invoice_date' => '2026-10-12',
            'tax_percentage' => '18',
            'round_off_mode' => 'auto',
            'items' => [['purchase_order_item_id' => $cement->id, 'quantity' => 1]],
        ])->assertRedirect();

        $auto = PurchaseInvoice::query()->where('invoice_number', 'INV-AUTO')->firstOrFail();
        $this->assertSame('0.00', (string) $auto->round_off);
        $this->assertSame('472.00', (string) $auto->total_amount);
    }

    public function test_duplicate_invoice_numbers_for_the_same_vendor_are_rejected(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order, $cement] = $this->order($user);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => '02',
            'invoice_date' => '2026-10-12',
            'tax_percentage' => '18',
            'items' => [['purchase_order_item_id' => $cement->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => '02',
            'invoice_date' => '2026-10-13',
            'tax_percentage' => '18',
            'items' => [['purchase_order_item_id' => $cement->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('invoice_number');

        $this->assertSame(1, PurchaseInvoice::query()->where('invoice_number', '02')->count());
    }

    public function test_selected_tax_rate_overrides_the_purchase_order_gst(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order, $cement] = $this->order($user);

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-BAD',
            'invoice_date' => '2026-10-12',
            'tax_percentage' => 7,
            'items' => [['purchase_order_item_id' => $cement->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('tax_percentage');

        $this->actingAs($user)->post(route('projects.purchase-invoices.store', $project), [
            'purchase_order_id' => $order->id,
            'invoice_number' => 'INV-12',
            'invoice_date' => '2026-10-12',
            'tax_percentage' => '12',
            'items' => [['purchase_order_item_id' => $cement->id, 'quantity' => 10]],
        ])->assertRedirect();

        $invoice = PurchaseInvoice::query()->where('invoice_number', 'INV-12')->firstOrFail();
        $this->markInvoiceApproved($invoice, $user);
        $this->assertSame('12.00', (string) $invoice->tax_percentage);
        $this->assertSame('4000.00', (string) $invoice->invoice_amount);
        $this->assertSame('480.00', (string) $invoice->tax_amount);
        $this->assertSame('4480.00', (string) $invoice->total_amount);
        $this->assertSame('240.00', (string) $invoice->items()->first()->cgst_amount);
        $this->assertSame('240.00', (string) $invoice->items()->first()->sgst_amount);

        $this->actingAs($user)->post(route('vendors.payments.store', $order->vendor), [
            'payment_date' => '2026-10-14',
            'payment_type' => VendorPayment::TYPE_INVOICE,
            'payment_mode' => 'bank_transfer',
            'amount' => 1500,
            'purchase_invoice_id' => $invoice->id,
        ])->assertRedirect();

        $this->actingAs($user)
            ->get(route('vendors.show', $order->vendor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.total_paid', '1500.00'));

        $this->actingAs($user)
            ->get(route('projects.purchase-invoices.pdf', [$project, $invoice]))
            ->assertOk();
    }

    /**
     * @return array{0: Project, 1: PurchaseOrder, 2: PurchaseOrderItem, 3: PurchaseOrderItem}
     */
    private function order(User $user): array
    {
        $vendor = Vendor::factory()->create();
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
            'gst_percentage' => 18,
        ])->assertRedirect();

        $order = PurchaseOrder::query()->firstOrFail();
        $order->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $user->id,
            'gst_percentage' => 18,
            'cgst_percentage' => 9,
            'sgst_percentage' => 9,
        ]);
        $cement = $order->items()->firstOrFail();
        $cement->update([
            'description' => 'Cement',
            'unit' => 'Bag',
            'quantity' => 20,
            'rate' => 400,
            'amount' => 8000,
            'discount_percentage' => 0,
            'boq_item_id' => $item->id,
        ]);
        $sand = $order->items()->create([
            'description' => 'Sand',
            'unit' => 'CUM',
            'quantity' => 8,
            'rate' => 50,
            'amount' => 400,
            'discount_percentage' => 0,
            'boq_item_id' => $item->id,
        ]);

        return [$project, $order->fresh(), $cement->fresh(), $sand];
    }
}
