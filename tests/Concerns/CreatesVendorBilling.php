<?php

namespace Tests\Concerns;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;

trait CreatesVendorBilling
{
    /**
     * @return array{0: Project, 1: PurchaseOrder}
     */
    protected function approvedOrder(User $user, Vendor $vendor, ?Project $project = null): array
    {
        $project ??= Project::factory()->create();
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

        $request = PurchaseRequest::query()->latest('id')->firstOrFail();
        $request->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id]);

        $this->actingAs($user)->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), [
            'po_date' => '2026-10-09',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
        ])->assertRedirect();

        $order = PurchaseOrder::query()->latest('id')->firstOrFail();
        $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id]);

        return [$project, $order->fresh()];
    }

    protected function recordedInvoice(User $user, Vendor $vendor, string $total = '10000.00', ?Project $project = null): PurchaseInvoice
    {
        [$project, $order] = $this->approvedOrder($user, $vendor, $project);

        return PurchaseInvoice::query()->create([
            'project_id' => $project->id,
            'purchase_order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'invoice_number' => 'INV-'.uniqid(),
            'invoice_date' => '2026-10-10',
            'invoice_amount' => $total,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => $total,
            'status' => 'recorded',
            'created_by' => $user->id,
        ]);
    }
}
