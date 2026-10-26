<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\BoqReceiptProgress;
use App\Services\BoqRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoqGrnProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_boq_progress_uses_posted_grn_accepted_quantity(): void
    {
        $user = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'description' => 'Cement',
            'quantity' => 7536,
            'rate' => 400,
            'unit' => 'Bag',
        ]);

        $this->actingAs($user)->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), [
            'request_date' => '2026-10-09',
            'description' => 'Cement',
            'requested_boq_quantity' => '1.000',
            'items' => [[
                'description' => 'Cement',
                'unit' => 'Bag',
                'quantity' => 100,
                'estimated_rate' => 400,
            ]],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->firstOrFail();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id]);
        $this->actingAs($user)->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), [
            'po_date' => '2026-10-09',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
        ])->assertRedirect();

        $first = PurchaseOrder::query()->firstOrFail();
        $first->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id]);
        $firstLine = $first->items()->firstOrFail();
        $firstLine->update(['quantity' => 100, 'boq_item_id' => $item->id]);

        $second = $this->copyOrder($first, 'PO-GRN-2', 150, $item->id);
        $third = $this->copyOrder($first, 'PO-GRN-3', 50, null);
        $third->items()->first()->update(['boq_item_id' => null]);

        $this->receipt($project, $user, $first, $firstLine->id, 'GRN-A', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_POSTED, 50, 50, 0);
        $this->receipt($project, $user, $first, $firstLine->id, 'GRN-B', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_POSTED, 30, 30, 0);
        $this->receipt($project, $user, $second, $second->items()->first()->id, 'GRN-C', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_POSTED, 120, 120, 0);
        $this->receipt($project, $user, $third, $third->items()->first()->id, 'GRN-DRAFT', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_DRAFT, 10, 10, 0);
        $this->receipt($project, $user, $third, $third->items()->first()->id, 'GRN-PENDING', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_SUBMITTED, 10, 10, 0);
        $this->receipt($project, $user, $third, $third->items()->first()->id, 'GRN-REJECTED', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_REJECTED, 10, 0, 10);
        $this->receipt($project, $user, $third, $third->items()->first()->id, 'GRN-DAMAGE', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_POSTED, 9, 0, 9);
        $this->receipt($project, $user, $third, $third->items()->first()->id, 'GRN-CANCEL', MaterialReceipt::KIND_GRN, MaterialReceipt::STATUS_CANCELLED, 40, 40, 0);
        $this->receipt($project, $user, $first, $firstLine->id, 'MR-STORE', MaterialReceipt::KIND_STORE, MaterialReceipt::STATUS_LEGACY, 40, 40, 0);

        $progress = app(BoqReceiptProgress::class);
        $this->assertSame('200.000', $progress->acceptedFor([$item->id])[$item->id]);
        $this->assertSame('209.000', $progress->recordedFor([$item->id])[$item->id]);
        $this->assertSame('300.000', $progress->orderedFor([$item->id])[$item->id]);

        $this->actingAs($user)
            ->get(route('projects.boqs.show', [$project, $boq]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('boq.items.0.quantity_usage.received_quantity', '209.000')
                ->where('boq.items.0.quantity_usage.used_quantity', '200.000')
                ->where('boq.items.0.quantity_usage.boq_quantity', '7536.000')
            );

        $this->actingAs($user)
            ->get(route('projects.boqs.items.procurement', [$project, $boq, $item]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receiptSummary.accepted_quantity', '200.000')
                ->where('receiptSummary.ordered_quantity', '300.000')
                ->where('quantitySummary.boq_quantity', '7536.000')
            );

        $original = $boq->revisions()->firstOrFail();
        $original->update(['status' => BoqRevision::FINALIZED]);
        $next = app(BoqRevisionService::class)->createNext($boq->fresh(), $user, 'Rate change');
        $copy = $next->items()->firstOrFail();
        $this->assertNotSame($item->id, $copy->id);
        $this->assertArrayNotHasKey($copy->id, $progress->acceptedFor([$copy->id]));
        $this->assertSame('200.000', $progress->acceptedFor([$item->id])[$item->id]);

        $this->actingAs($user)
            ->get(route('projects.boqs.show', [$project, $boq]).'?revision='.$original->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('boq.items.0.quantity_usage.received_quantity', '209.000'));

        $this->actingAs($user)
            ->get(route('projects.boqs.show', [$project, $boq]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('boq.items.0.quantity_usage', null));
    }

    private function copyOrder(PurchaseOrder $source, string $number, string $quantity, ?int $boqItemId): PurchaseOrder
    {
        $copy = $source->replicate();
        $copy->po_number = $number;
        $copy->save();
        $line = $source->items()->first()->replicate();
        $line->purchase_order_id = $copy->id;
        $line->quantity = $quantity;
        $line->boq_item_id = $boqItemId;
        $line->save();

        return $copy->fresh('items');
    }

    private function receipt(Project $project, User $user, PurchaseOrder $order, int $lineId, string $number, string $kind, string $status, string $received, string $accepted, string $rejected): void
    {
        $receipt = MaterialReceipt::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'receipt_number' => $number,
            'document_kind' => $kind,
            'status' => $status,
            'receipt_date' => '2026-10-12',
            'received_by' => 'Store keeper',
            'created_by' => $user->id,
        ]);
        $receipt->items()->create([
            'purchase_order_item_id' => $lineId,
            'description' => 'Cement',
            'unit' => 'Bag',
            'quantity_received' => $received,
            'quantity_accepted' => $accepted,
            'quantity_rejected' => $rejected,
        ]);
    }
}
