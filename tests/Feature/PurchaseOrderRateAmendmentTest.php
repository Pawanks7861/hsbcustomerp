<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderRateAmendment;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderRateAmendmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_rate_edit_still_works_on_update(): void
    {
        $user = User::factory()->admin()->create();
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($user, approvePo: false);

        $this->actingAs($user)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->orderHeader([
                'rate_change_reason' => 'Vendor quote',
                'items' => [[
                    'rate' => 125,
                ]],
            ]))
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('125.00', (string) $item->rate);
        $this->assertSame('250.00', (string) $item->amount);
    }

    public function test_cannot_update_approved_purchase_order_via_update(): void
    {
        $user = User::factory()->admin()->create();
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($user);

        $this->actingAs($user)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->orderHeader([
                'items' => [[
                    'rate' => 50,
                ]],
            ]))
            ->assertForbidden();

        $this->assertSame('100.00', (string) $item->fresh()->rate);
    }

    public function test_can_request_rate_amendment_on_approved_purchase_order(): void
    {
        $requester = User::factory()->withRoles('purchase_manager')->create();
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($requester);

        $this->actingAs($requester)
            ->post(route('projects.purchase-orders.rate-amendments.store', [$project, $order]), [
                'purchase_order_item_id' => $item->id,
                'proposed_rate' => '90.00',
                'reason' => 'Vendor discount after approval',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_order_rate_amendments', [
            'purchase_order_id' => $order->id,
            'purchase_order_item_id' => $item->id,
            'previous_rate' => '100.00',
            'proposed_rate' => '90.00',
            'status' => PurchaseOrderRateAmendment::STATUS_PENDING,
        ]);
    }

    public function test_pending_amendment_does_not_change_item_rate(): void
    {
        $requester = User::factory()->withRoles('purchase_manager')->create();
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($requester);

        $this->actingAs($requester)
            ->post(route('projects.purchase-orders.rate-amendments.store', [$project, $order]), [
                'purchase_order_item_id' => $item->id,
                'proposed_rate' => '80.00',
                'reason' => 'Awaiting approval',
            ])
            ->assertRedirect();

        $item->refresh();
        $order->refresh();
        $this->assertSame('100.00', (string) $item->rate);
        $this->assertSame('200.00', (string) $item->amount);
        $this->assertSame('200.00', (string) $order->grand_total);
    }

    public function test_approve_applies_new_rate_and_recalculates_totals(): void
    {
        $admin = User::factory()->admin()->create();
        $requester = User::factory()->withRoles('purchase_manager')->create();
        $approver = User::factory()->withRoles('purchase_approver')->create();
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($requester, admin: $admin);

        $this->actingAs($requester)
            ->post(route('projects.purchase-orders.rate-amendments.store', [$project, $order]), [
                'purchase_order_item_id' => $item->id,
                'proposed_rate' => '90.00',
                'reason' => 'Approved vendor revision',
            ])
            ->assertRedirect();

        $amendment = PurchaseOrderRateAmendment::query()->firstOrFail();

        $this->actingAs($approver)
            ->post(route('projects.purchase-orders.rate-amendments.approve', [$project, $order, $amendment]))
            ->assertRedirect();

        $item->refresh();
        $order->refresh();
        $amendment->refresh();

        $this->assertSame(PurchaseOrderRateAmendment::STATUS_APPROVED, $amendment->status);
        $this->assertSame('90.00', (string) $item->rate);
        $this->assertSame('180.00', (string) $item->amount);
        $this->assertSame('180.00', (string) $order->grand_total);
        $this->assertTrue(ActivityLog::query()->where('action', 'purchase_order.rate_amendment_approved')->exists());
    }

    public function test_reject_leaves_rate_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $requester = User::factory()->withRoles('purchase_manager')->create();
        $approver = User::factory()->withRoles('purchase_approver')->create();
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($requester, admin: $admin);

        $this->actingAs($requester)
            ->post(route('projects.purchase-orders.rate-amendments.store', [$project, $order]), [
                'purchase_order_item_id' => $item->id,
                'proposed_rate' => '75.00',
                'reason' => 'Too low',
            ])
            ->assertRedirect();

        $amendment = PurchaseOrderRateAmendment::query()->firstOrFail();

        $this->actingAs($approver)
            ->post(route('projects.purchase-orders.rate-amendments.reject', [$project, $order, $amendment]), [
                'decision_comment' => 'Not acceptable',
            ])
            ->assertRedirect();

        $item->refresh();
        $amendment->refresh();

        $this->assertSame(PurchaseOrderRateAmendment::STATUS_REJECTED, $amendment->status);
        $this->assertSame('100.00', (string) $item->rate);
        $this->assertTrue(ActivityLog::query()->where('action', 'purchase_order.rate_amendment_rejected')->exists());
    }

    public function test_unauthorized_user_cannot_request_rate_amendment(): void
    {
        $operator = User::factory()->admin()->create();
        $user = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);
        [$project, , , $order, $item] = $this->approvedPurchaseOrder($operator);

        $this->actingAs($user)
            ->post(route('projects.purchase-orders.rate-amendments.store', [$project, $order]), [
                'purchase_order_item_id' => $item->id,
                'proposed_rate' => '90.00',
                'reason' => 'Should fail',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Project, 1: Boq, 2: BoqItem, 3: PurchaseOrder, 4: PurchaseOrderItem}
     */
    private function approvedPurchaseOrder(User $user, ?User $admin = null, bool $approvePo = true): array
    {
        $admin ??= User::factory()->admin()->create();
        [$project, $boq, $item] = $this->procurable();

        $request = $this->makeRequest($user, $project, $boq, $item);
        $request->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), $this->orderHeader())
            ->assertRedirect();

        $order = PurchaseOrder::query()->firstOrFail();
        $order->load('items');
        $line = $order->items->first();

        if ($approvePo) {
            $this->actingAs($user)->post(route('projects.purchase-orders.submit', [$project, $order]))->assertRedirect();
            $this->completePoApproval($order, $admin);
            $order->refresh();
            $line->refresh();
        }

        return [$project, $boq, $item, $order, $line];
    }

    /**
     * @return array{0: Project, 1: Boq, 2: BoqItem}
     */
    private function procurable(): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create([
            'project_id' => $project->id,
            'gst_percentage' => 0,
        ]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'quantity' => 100,
            'rate' => 100,
            'unit' => 'Bag',
        ]);

        return [$project, $boq, $item];
    }

    private function makeRequest(User $user, Project $project, Boq $boq, BoqItem $item): PurchaseRequest
    {
        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), [
                'request_date' => '2026-10-08',
                'description' => 'Materials',
                'requested_boq_quantity' => '2.000',
                'items' => [[
                    'description' => 'Cement',
                    'unit' => 'Bag',
                    'quantity' => 2,
                    'estimated_rate' => 100,
                ]],
            ])->assertRedirect();

        return PurchaseRequest::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function orderHeader(array $extra = []): array
    {
        $vendor = Vendor::factory()->create(['status' => 'active']);

        return [
            'po_date' => '2026-10-08',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
            ...$extra,
        ];
    }
}
