<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_request_workflow_approval_and_project_isolation(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        [$project, $boq, $item] = $this->procurable();
        $other = Project::factory()->create();

        $header = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '2',
            'description' => 'Header only',
            'quantity' => null,
            'rate' => null,
        ]);

        $this->actingAs($user)
            ->get(route('projects.boqs.items.purchase-requests.create', [$project, $boq, $header]))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), [
                'request_date' => '2026-10-06',
                'required_date' => '2026-10-20',
                'description' => 'Foundation materials',
                'requested_boq_quantity' => '0.250',
                'items' => [
                    [
                        'description' => 'Cement',
                        'unit' => 'Bag',
                        'quantity' => 4,
                        'estimated_rate' => 12.5,
                        'estimated_amount' => 999,
                        'remarks' => 'OPC',
                    ],
                    [
                        'description' => 'Sand',
                        'unit' => 'Cum',
                        'quantity' => 2,
                        'estimated_rate' => null,
                    ],
                ],
            ])->assertRedirect();

        $request = PurchaseRequest::query()->firstOrFail();
        $this->assertSame('PR-'.now()->year.'-001', $request->pr_number);
        $this->assertSame($project->id, $request->project_id);
        $this->assertSame($boq->id, $request->boq_id);
        $this->assertSame($item->id, $request->boq_item_id);
        $this->assertSame($user->id, $request->requested_by);
        $this->assertSame('50.00', (string) $request->items[0]->estimated_amount);
        $this->assertNull($request->items[1]->estimated_amount);
        $this->assertSame('50.00', $request->estimatedTotal());

        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), $this->requestPayload())
            ->assertRedirect();
        $this->assertSame('PR-'.now()->year.'-002', PurchaseRequest::query()->orderByDesc('id')->first()->pr_number);

        $this->actingAs($user)
            ->put(route('projects.purchase-requests.update', [$project, $request]), $this->requestPayload())
            ->assertRedirect();
        $this->assertSame('20.00', $request->fresh()->estimatedTotal());

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $request]))
            ->assertRedirect();
        $this->assertSame('submitted', $request->fresh()->status);

        $this->actingAs($user)
            ->put(route('projects.purchase-requests.update', [$project, $request]), $this->requestPayload())
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('projects.purchase-requests.destroy', [$project, $request]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.approve', [$project, $request]))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.reject', [$project, $request]), [
                'rejection_reason' => 'Not now',
            ])->assertForbidden();
        $this->assertSame('submitted', $request->fresh()->status);

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.reject', [$project, $request]), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.reject', [$project, $request]), [
                'rejection_reason' => 'Revise quantities',
            ])->assertRedirect();

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame($admin->id, $request->rejected_by);
        $this->assertSame('Revise quantities', $request->rejection_reason);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.reopen', [$project, $request]))
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $request]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.approve', [$project, $request]))
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->approved_by);
        $this->assertNotNull($request->approved_at);

        $this->actingAs($admin)
            ->put(route('projects.purchase-requests.update', [$project, $request]), $this->requestPayload())
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('projects.purchase-requests.show', [$other, $request]))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('projects.boqs.items.procurement', [$other, $boq, $item]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('projects.boqs.items.procurement', [$project, $boq, $item]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Boqs/Procurement')
                ->has('purchaseRequests', 2)
                ->where('purchaseRequests.1.pr_number', $request->pr_number)
                ->has('purchaseOrders', 0)
            );
    }

    public function test_purchase_order_workflow_calculations_and_locks(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        [$project, $boq, $item] = $this->procurable();
        $other = Project::factory()->create();

        $draft = $this->makeRequest($user, $project, $boq, $item);
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$project, $draft]), $this->orderPayload())
            ->assertForbidden();

        $draft->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($user)
            ->get(route('projects.purchase-requests.purchase-orders.create', [$project, $draft]))
            ->assertForbidden();

        $approved = $this->makeRequest($user, $project, $boq, $item, 'Cement');
        $approved->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);

        $this->actingAs($user)
            ->get(route('projects.purchase-requests.purchase-orders.create', [$project, $approved]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.items.0.description', 'Cement')
                ->where('purchaseOrder.items.0.unit', 'Bag')
                ->where('purchaseOrder.items.0.quantity', '2.000')
                ->where('purchaseOrder.items.0.rate', '10.00')
            );

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$project, $approved]), $this->orderPayload([
                'grand_total' => '1.00',
                'gst_amount' => '0.00',
            ]))->assertRedirect();

        $order = PurchaseOrder::query()->firstOrFail();
        $this->assertSame('PO-'.now()->year.'-001', $order->po_number);
        $this->assertSame($approved->id, $order->purchase_request_id);
        $this->assertSame($project->id, $order->project_id);
        $this->assertSame($boq->id, $order->boq_id);
        $this->assertSame($item->id, $order->boq_item_id);
        $this->assertSame($user->id, $order->created_by);
        $this->assertSame('10.00', (string) $order->items[0]->rate);
        $this->assertSame('20.00', (string) $order->items[0]->amount);
        $this->assertSame('20.00', (string) $order->subtotal);
        $this->assertSame('5.00', (string) $order->gst_percentage);
        $this->assertSame('1.00', (string) $order->gst_amount);
        $this->assertSame('21.00', (string) $order->grand_total);

        $this->actingAs($user)
            ->post(route('projects.purchase-orders.submit', [$project, $order]))
            ->assertRedirect();
        $this->assertSame('submitted', $order->fresh()->status);

        $this->actingAs($user)
            ->post(route('projects.purchase-orders.approve', [$project, $order]))
            ->assertForbidden();
        $this->actingAs($user)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->orderPayload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('projects.purchase-orders.reject', [$project, $order]), [
                'rejection_reason' => 'Check the rate',
            ])->assertRedirect();
        $this->assertSame('rejected', $order->fresh()->status);

        $this->actingAs($user)
            ->post(route('projects.purchase-orders.reopen', [$project, $order]))
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('projects.purchase-orders.submit', [$project, $order]))
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('projects.purchase-orders.approve', [$project, $order]))
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($admin->id, $order->approved_by);

        $this->actingAs($admin)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->orderPayload())
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('projects.purchase-orders.destroy', [$project, $order]))
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('projects.purchase-requests.destroy', [$project, $approved]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('projects.purchase-orders.show', [$other, $order]))
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$other, $approved]), $this->orderPayload())
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('projects.boqs.items.procurement', [$project, $boq, $item]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders.0.po_number', $order->po_number)
                ->where('purchaseOrders.0.pr_number', $approved->pr_number)
            );
    }

    public function test_boq_item_with_procurement_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        [$project, $boq, $item] = $this->procurable();
        $this->makeRequest($user, $project, $boq, $item);

        $this->actingAs($user)
            ->put(route('projects.boqs.update', [$project, $boq]), [
                'title' => $boq->title,
                'gst_percentage' => 0,
                'items' => [],
            ])->assertSessionHasErrors('items');

        $this->assertNotNull($item->fresh());

        $this->actingAs($user)
            ->delete(route('projects.boqs.destroy', [$project, $boq]))
            ->assertRedirect()
            ->assertSessionHas('error', 'This BOQ cannot be deleted because procurement records exist.');
        $this->assertNotNull($boq->fresh());

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect()
            ->assertSessionHas('error', 'This project cannot be deleted because procurement records exist.');
        $this->assertNotNull($project->fresh());
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
            'item_number' => '1.1',
            'description' => 'Construction of Light Pole Foundation',
            'unit' => 'Nos',
            'quantity' => 1,
            'rate' => 100,
        ]);

        return [$project, $boq, $item];
    }

    private function makeRequest(User $user, Project $project, Boq $boq, BoqItem $item, string $description = 'Cement'): PurchaseRequest
    {
        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), $this->requestPayload($description))
            ->assertRedirect();

        return PurchaseRequest::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(string $description = 'Cement'): array
    {
        return [
            'request_date' => '2026-10-06',
            'description' => 'Materials',
            'requested_boq_quantity' => '0.250',
            'items' => [
                [
                    'description' => $description,
                    'unit' => 'Bag',
                    'quantity' => 2,
                    'estimated_rate' => 10,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function orderPayload(array $extra = []): array
    {
        $vendor = Vendor::factory()->create([
            'name' => $extra['supplier_name'] ?? 'Local Supplier',
            'status' => 'active',
        ]);
        unset($extra['supplier_name']);

        return [
            'po_date' => '2026-10-06',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 5,
            'items' => [
                [
                    'description' => 'Cement',
                    'unit' => 'Bag',
                    'quantity' => 2,
                    'rate' => 10.5,
                ],
            ],
            ...$extra,
        ];
    }
}
