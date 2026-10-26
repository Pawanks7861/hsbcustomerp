<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderRateAmendment;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestPoSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr_item_changes_synchronize_to_the_linked_po_line(): void
    {
        [$user, $admin, $project, $request, $order] = $this->linkedDraftOrder();
        $line = $request->items()->firstOrFail();
        $poLine = $order->items()->firstOrFail();
        $originalRate = (string) $poLine->rate;

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'id' => $line->id,
                'name' => 'Renamed site cement',
                'description' => 'Updated description',
                'unit' => 'Nos',
                'quantity' => 7,
                'boq_item_id' => $line->boq_item_id,
            ]],
        ])->assertRedirect();

        $order->refresh()->load('items');
        $synced = $order->items()->where('purchase_request_item_id', $line->id)->firstOrFail();
        $this->assertSame('Renamed site cement', $synced->name);
        $this->assertSame('Updated description', $synced->description);
        $this->assertSame('Nos', $synced->unit);
        $this->assertSame('7.000', (string) $synced->quantity);
        $this->assertSame($originalRate, (string) $synced->rate);
        $this->assertSame('700.00', (string) $synced->amount);
        $this->assertSame('700.00', (string) $order->subtotal);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'purchase_order.synced_from_pr',
            'subject_type' => PurchaseOrder::class,
            'subject_id' => $order->id,
        ]);
    }

    public function test_adding_a_pr_item_creates_one_po_line_and_removing_it_is_safe_without_receipts(): void
    {
        [$user, $admin, $project, $request, $order] = $this->linkedDraftOrder();
        $existing = $request->items()->firstOrFail();

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [
                [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'description' => $existing->description,
                    'unit' => $existing->unit,
                    'quantity' => $existing->quantity,
                    'boq_item_id' => $existing->boq_item_id,
                ],
                [
                    'name' => 'Extra binding wire',
                    'description' => 'Soft drawn',
                    'unit' => 'Kg',
                    'quantity' => 3,
                ],
            ],
        ])->assertRedirect();

        $order->refresh()->load('items');
        $this->assertCount(2, $order->items);
        $this->assertCount(2, $order->items->pluck('purchase_request_item_id')->unique());
        $added = $order->items->firstWhere('name', 'Extra binding wire');
        $this->assertNotNull($added);
        $this->assertSame('3.000', (string) $added->quantity);

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request->fresh()]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'id' => $existing->id,
                'name' => $existing->name,
                'description' => $existing->description,
                'unit' => $existing->unit,
                'quantity' => $existing->quantity,
                'boq_item_id' => $existing->boq_item_id,
            ]],
        ])->assertRedirect();

        $this->assertCount(1, $order->fresh('items')->items);
        $this->assertSame($existing->id, $order->fresh('items')->items->first()->purchase_request_item_id);
    }

    public function test_changing_the_optional_boq_tag_does_not_overwrite_item_details_or_po_rate(): void
    {
        [$user, $admin, $project, $request, $order] = $this->linkedDraftOrder();
        $second = BoqItem::factory()->create([
            'boq_id' => $request->boq_id,
            'description' => 'Labour tag only',
            'unit' => 'Nos',
            'quantity' => 30,
            'rate' => 50,
        ]);
        $line = $request->items()->firstOrFail();
        $rate = (string) $order->items()->first()->rate;

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'id' => $line->id,
                'name' => 'Keep this name',
                'description' => 'Keep this description',
                'unit' => 'Bag',
                'quantity' => $line->quantity,
                'boq_item_id' => $second->id,
            ]],
        ])->assertRedirect();

        $synced = $order->fresh('items')->items->first();
        $this->assertSame($second->id, $synced->boq_item_id);
        $this->assertSame('Keep this name', $synced->name);
        $this->assertSame('Keep this description', $synced->description);
        $this->assertSame($rate, (string) $synced->rate);
    }

    public function test_rate_amendment_history_survives_pr_synchronization(): void
    {
        [$user, $admin, $project, $request, $order] = $this->linkedDraftOrder();
        $this->actingAs($user)->post(route('projects.purchase-orders.submit', [$project, $order]));
        $this->completePoApproval($order, $admin);
        $poLine = $order->fresh('items')->items->first();

        PurchaseOrderRateAmendment::query()->create([
            'purchase_order_id' => $order->id,
            'purchase_order_item_id' => $poLine->id,
            'previous_rate' => $poLine->rate,
            'proposed_rate' => '150.00',
            'previous_amount' => $poLine->amount,
            'proposed_amount' => '1500.00',
            'reason' => 'Vendor revision',
            'status' => PurchaseOrderRateAmendment::STATUS_APPROVED,
            'requested_by' => $user->id,
            'requested_at' => now(),
            'approved_by' => $admin->id,
            'decided_at' => now(),
        ]);
        $poLine->update(['rate' => '150.00', 'amount' => '1500.00']);

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'id' => $request->items()->first()->id,
                'name' => 'Amended line name',
                'description' => $request->items()->first()->description,
                'unit' => $request->items()->first()->unit,
                'quantity' => 8,
                'boq_item_id' => $request->items()->first()->boq_item_id,
            ]],
        ])->assertRedirect();

        $synced = $order->fresh('items')->items->first();
        $this->assertSame('Amended line name', $synced->name);
        $this->assertSame('150.00', (string) $synced->rate);
        $this->assertSame('1200.00', (string) $synced->amount);
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertDatabaseHas('purchase_order_rate_amendments', [
            'purchase_order_id' => $order->id,
            'reason' => 'Vendor revision',
            'proposed_rate' => '150.00',
        ]);
    }

    public function test_received_quantities_block_unsafe_pr_edits_and_roll_back(): void
    {
        [$user, $admin, $project, $request, $order] = $this->linkedDraftOrder();
        $this->actingAs($user)->post(route('projects.purchase-orders.submit', [$project, $order]));
        $this->completePoApproval($order, $admin);
        $poLine = $order->fresh('items')->items->first();
        $prLine = $request->items()->firstOrFail();

        $receipt = MaterialReceipt::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'receipt_number' => 'GRN-SYNC-1',
            'document_kind' => MaterialReceipt::KIND_GRN,
            'status' => MaterialReceipt::STATUS_POSTED,
            'receipt_date' => '2026-10-23',
            'received_by' => 'Store keeper',
            'created_by' => $user->id,
        ]);
        $receipt->items()->create([
            'purchase_order_item_id' => $poLine->id,
            'description' => 'Received bags',
            'unit' => 'Bag',
            'quantity_received' => '6.000',
            'quantity_accepted' => '6.000',
            'quantity_rejected' => '0.000',
        ]);

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'id' => $prLine->id,
                'name' => $prLine->name,
                'description' => $prLine->description,
                'unit' => $prLine->unit,
                'quantity' => 2,
                'boq_item_id' => $prLine->boq_item_id,
            ]],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->assertSame('10.000', (string) $prLine->fresh()->quantity);
        $this->assertSame('10.000', (string) $poLine->fresh()->quantity);

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'id' => $prLine->id,
                'name' => 'Still named after receipt',
                'description' => $prLine->description,
                'unit' => $prLine->unit,
                'quantity' => 10,
                'boq_item_id' => $prLine->boq_item_id,
            ]],
        ])->assertRedirect();

        $this->assertSame('Still named after receipt', $poLine->fresh()->name);
        $this->assertSame('10.000', (string) $poLine->fresh()->quantity);
        $this->assertSame('6.000', (string) $receipt->items()->first()->quantity_accepted);
    }

    public function test_invoiced_po_lines_cannot_be_removed_from_the_pr(): void
    {
        [$user, $admin, $project, $request, $order] = $this->linkedDraftOrder();
        $this->actingAs($user)->post(route('projects.purchase-orders.submit', [$project, $order]));
        $this->completePoApproval($order, $admin);
        $poLine = $order->fresh('items')->items->first();
        $prLine = $request->items()->firstOrFail();

        $invoice = PurchaseInvoice::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'invoice_number' => 'PIN-SYNC-1',
            'invoice_date' => '2026-10-23',
            'invoice_amount' => 1000,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 1000,
            'status' => 'recorded',
            'approval_status' => PurchaseInvoice::APPROVAL_APPROVED,
            'created_by' => $user->id,
        ]);
        $invoice->items()->create([
            'purchase_order_item_id' => $poLine->id,
            'description' => $poLine->description,
            'unit' => $poLine->unit,
            'po_quantity' => $poLine->quantity,
            'previously_invoiced' => 0,
            'quantity' => 4,
            'rate' => $poLine->rate,
            'discount_percentage' => 0,
            'amount' => 400,
            'taxable_amount' => 400,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'line_total' => 400,
        ]);

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [[
                'name' => 'Replacement only',
                'description' => 'Should not replace invoiced line',
                'unit' => 'Bag',
                'quantity' => 1,
            ]],
        ])->assertSessionHasErrors();

        $this->assertTrue($request->fresh('items')->items->contains('id', $prLine->id));
        $this->assertTrue($order->fresh('items')->items->contains('id', $poLine->id));
    }

    public function test_draft_only_users_cannot_edit_a_submitted_pr(): void
    {
        $user = User::factory()->withRoles('site_engineer')->create();
        $project = Project::factory()->create();
        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-23',
            'items' => [['name' => 'Draft item', 'quantity' => 1, 'unit' => 'Nos']],
        ])->assertRedirect();
        $request = PurchaseRequest::query()->firstOrFail();
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $request]));

        $this->actingAs($user)->put(route('projects.purchase-requests.lines.update', [$project, $request]), [
            'request_date' => '2026-10-23',
            'items' => [['id' => $request->items()->first()->id, 'name' => 'Hacked', 'quantity' => 9]],
        ])->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: Project, 3: PurchaseRequest, 4: PurchaseOrder}
     */
    private function linkedDraftOrder(): array
    {
        $user = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'description' => 'Cement',
            'unit' => 'Bag',
            'quantity' => 40,
            'rate' => 100,
        ]);

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-23',
            'items' => [[
                'name' => 'Site cement',
                'description' => 'Bags for columns',
                'unit' => 'Bag',
                'quantity' => 10,
                'boq_item_id' => $item->id,
            ]],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->firstOrFail();
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $request]));
        $this->actingAs($admin)->post(route('projects.purchase-requests.approve', [$project, $request]));
        $vendor = Vendor::factory()->create(['name' => 'Sync Vendor']);
        $this->actingAs($user)->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), [
            'po_date' => '2026-10-23',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
        ])->assertRedirect();

        return [$user, $admin, $project, $request->fresh('items'), PurchaseOrder::query()->firstOrFail()->fresh('items')];
    }
}
