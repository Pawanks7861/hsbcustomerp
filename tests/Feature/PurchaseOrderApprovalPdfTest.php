<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderApprovalPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_shows_item_description_and_blocks_pdf_until_both_approvals(): void
    {
        $user = User::factory()->admin()->create();
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();
        [$project, $order] = $this->submittedOrder($user, 'OPC 53 grade cement');

        $this->actingAs($user)
            ->get(route('projects.purchase-orders.show', [$project, $order]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('purchaseOrder.items.0.description', 'OPC 53 grade cement')
                ->where('purchaseOrder.boq_item', 'OPC 53 grade cement')
                ->where('abilities.print', false)
            );

        $this->actingAs($first)
            ->get(route('projects.purchase-orders.pdf', [$project, $order]))
            ->assertForbidden();
        $this->actingAs($first)
            ->get(route('projects.purchase-orders.pdf.download', [$project, $order]))
            ->assertForbidden();

        $this->actingAs($first)->post(route('projects.purchase-orders.approve', [$project, $order]))->assertRedirect();
        $order->refresh();
        $this->assertSame(DocumentStatus::SUBMITTED, $order->status);
        $this->assertSame($first->id, $order->first_approved_by);
        $this->assertNull($order->second_approved_by);

        $this->actingAs($first)->post(route('projects.purchase-orders.approve', [$project, $order]))->assertForbidden();
        $this->actingAs($first)
            ->get(route('projects.purchase-orders.pdf', [$project, $order]))
            ->assertForbidden();

        $this->actingAs($second)->post(route('projects.purchase-orders.approve', [$project, $order]))->assertRedirect();
        $order->refresh();
        $this->assertSame(DocumentStatus::APPROVED, $order->status);
        $this->assertSame($second->id, $order->second_approved_by);

        $this->actingAs($user)
            ->get(route('projects.purchase-orders.pdf', [$project, $order]))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('projects.purchase-orders.pdf.download', [$project, $order]))
            ->assertOk();
    }

    public function test_new_po_shows_recorded_grn_quantity(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $order] = $this->submittedOrder($user, 'Cement');
        $order->update(['status' => DocumentStatus::APPROVED, 'approved_by' => $user->id, 'approved_at' => now()]);
        $line = $order->items()->firstOrFail();

        $receipt = MaterialReceipt::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'receipt_number' => 'GRN-REF-1',
            'document_kind' => MaterialReceipt::KIND_GRN,
            'status' => MaterialReceipt::STATUS_POSTED,
            'receipt_date' => '2026-10-12',
            'received_by' => 'Store keeper',
            'created_by' => $user->id,
        ]);
        $receipt->items()->create([
            'purchase_order_item_id' => $line->id,
            'description' => 'Cement',
            'unit' => 'Bag',
            'quantity_received' => '7.000',
            'quantity_accepted' => '2.000',
            'quantity_rejected' => '5.000',
        ]);

        $pr = $order->purchaseRequest;
        $this->actingAs($user)
            ->get(route('projects.purchase-requests.purchase-orders.create', [$project, $pr]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('purchaseOrder.items.0.previous_grn_quantity', '7.000')
                ->where('purchaseOrder.items.0.description', 'Cement')
            );
    }

    /**
     * @return array{0: Project, 1: PurchaseOrder}
     */
    private function submittedOrder(User $user, string $description): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '1.1',
            'description' => $description,
            'quantity' => 100,
            'rate' => 10,
            'unit' => 'Bag',
        ]);
        $vendor = Vendor::factory()->create();

        $this->actingAs($user)->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), [
            'request_date' => '2026-10-09',
            'description' => $description,
            'requested_boq_quantity' => '2.000',
            'items' => [[
                'description' => $description,
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
        $this->actingAs($user)->post(route('projects.purchase-orders.submit', [$project, $order]))->assertRedirect();

        return [$project, $order->fresh('items')];
    }
}
