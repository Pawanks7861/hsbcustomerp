<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\CompanySetting;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\WorkOrder;
use App\Services\BoqReceiptProgress;
use App\Services\BoqRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_can_contain_four_boq_items_for_one_contractor(): void
    {
        [$user, $project, $vendor, $items] = $this->scene();

        $this->actingAs($user)
            ->post(route('projects.work-orders.store', $project), $this->payload($vendor, $items))
            ->assertRedirect();

        $order = WorkOrder::query()->with('items')->firstOrFail();
        $this->assertMatchesRegularExpression('/^WO-\d{4}-\d{3}$/', $order->work_order_number);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame('Sharma Contractors', $order->contractor_name);
        $this->assertCount(4, $order->items);
        $this->assertSame('100000.00', (string) $order->items[0]->amount);
        $this->assertSame('70000.00', (string) $order->items[1]->amount);
        $this->assertSame('60000.00', (string) $order->items[2]->amount);
        $this->assertSame('75000.00', (string) $order->items[3]->amount);
        $this->assertSame('305000.00', (string) $order->subtotal);
        $this->assertSame('0.00', (string) $order->gst_amount);
        $this->assertSame('305000.00', (string) $order->grand_total);
        $this->assertSame('1000.000', (string) $items[0]->fresh()->quantity);
        $this->assertSame('100.00', (string) $items[0]->fresh()->rate);

        $this->actingAs($user)
            ->get(route('projects.work-orders.index', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrders.0.item_count', 4)
                ->where('workOrders.0.grand_total', '305000.00')
                ->where('workOrders.0.contractor_name', 'Sharma Contractors')
            );
    }

    public function test_only_active_contractors_can_be_assigned(): void
    {
        [$user, $project, , $items] = $this->scene();
        $supplier = Vendor::factory()->create(['party_type' => Vendor::VENDOR, 'status' => Vendor::ACTIVE]);
        $inactive = Vendor::factory()->create(['party_type' => Vendor::CONTRACTOR, 'status' => Vendor::INACTIVE]);

        $this->actingAs($user)
            ->post(route('projects.work-orders.store', $project), $this->payload($supplier, $items))
            ->assertSessionHasErrors('vendor_id');

        $this->actingAs($user)
            ->post(route('projects.work-orders.store', $project), $this->payload($inactive, $items))
            ->assertSessionHasErrors('vendor_id');

        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_boq_items_from_another_project_are_rejected(): void
    {
        [$user, $project, $vendor, $items] = $this->scene();
        $other = Project::factory()->create();
        $otherBoq = Boq::factory()->create(['project_id' => $other->id]);
        $foreign = BoqItem::factory()->create([
            'boq_id' => $otherBoq->id,
            'description' => 'Foreign item',
            'quantity' => 10,
            'rate' => 10,
        ]);
        $payload = $this->payload($vendor, $items);
        $payload['items'][0]['boq_item_id'] = $foreign->id;

        $this->actingAs($user)
            ->post(route('projects.work-orders.store', $project), $payload)
            ->assertSessionHasErrors('items.0.boq_item_id');
    }

    public function test_quantity_is_allocated_across_work_orders_and_drafts_do_not_count(): void
    {
        [$user, $project, $vendor, $items] = $this->scene();
        $brickwork = $items[0];

        $this->actingAs($user)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$brickwork], [400]))->assertRedirect();
        $first = WorkOrder::query()->firstOrFail();
        $this->actingAs($user)->post(route('projects.work-orders.submit', [$project, $first]))->assertRedirect();

        $this->actingAs($user)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$brickwork], [100]))->assertRedirect();
        $draft = WorkOrder::query()->where('status', WorkOrder::DRAFT)->firstOrFail();

        $this->actingAs($user)
            ->get(route('projects.work-orders.create', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('catalog.0.previously_allocated', '400.000'));

        $this->actingAs($user)->post(route('projects.work-orders.cancel', [$project, $first]), [
            'cancellation_reason' => 'Released',
        ])->assertRedirect();

        $this->actingAs($user)
            ->get(route('projects.work-orders.create', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('catalog.0.previously_allocated', '0.000'));

        $this->assertSame('100.000', (string) $draft->fresh()->items()->first()->quantity);
    }

    public function test_excess_quantity_requires_authorized_approval_and_pending_orders_reserve_quantity(): void
    {
        [$admin, $project, $vendor, $items] = $this->scene();
        $clerk = User::factory()->create();
        $brickwork = $items[0];

        $this->actingAs($admin)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$brickwork], [1000]))->assertRedirect();
        $first = WorkOrder::query()->firstOrFail();
        $this->actingAs($admin)->post(route('projects.work-orders.submit', [$project, $first]))->assertRedirect();

        $this->actingAs($admin)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$brickwork], [1000]))->assertRedirect();
        $second = WorkOrder::query()->where('status', WorkOrder::DRAFT)->firstOrFail();
        $this->actingAs($admin)->post(route('projects.work-orders.submit', [$project, $second]))->assertRedirect();
        $second->refresh();
        $this->assertTrue($second->is_quantity_exceeded);
        $this->assertSame('1000.000', (string) $second->items()->first()->excess_quantity);

        $this->actingAs($admin)
            ->post(route('projects.work-orders.approve', [$project, $second]))
            ->assertSessionHasErrors('approval_remarks');

        $this->actingAs($clerk)
            ->post(route('projects.work-orders.approve', [$project, $second]), [
                'approval_remarks' => 'Allow the extra quantity',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('projects.work-orders.approve', [$project, $second]), [
                'approval_remarks' => 'Allow the extra quantity',
            ])
            ->assertRedirect();

        $second->refresh();
        $this->assertSame(WorkOrder::APPROVED, $second->status);
        $this->assertNotNull($second->approved_at);
        $this->assertSame($admin->id, $second->approved_by);
        $this->assertSame('Allow the extra quantity', $second->approval_remarks);
        $this->assertSame('1000.000', (string) $brickwork->fresh()->quantity);
    }

    public function test_approval_issue_amendment_and_cancellation_are_recorded(): void
    {
        [$admin, $project, $vendor, $items] = $this->scene();
        $brickwork = $items[0];
        $this->actingAs($admin)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$brickwork], [200]))->assertRedirect();
        $order = WorkOrder::query()->firstOrFail();

        $this->actingAs($admin)->put(route('projects.work-orders.update', [$project, $order]), $this->payload($vendor, [$brickwork], [180]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.submit', [$project, $order]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.reject', [$project, $order]), [
            'rejection_reason' => 'Revise the quantity',
        ])->assertRedirect();
        $this->assertSame(WorkOrder::REJECTED, $order->fresh()->status);

        $this->actingAs($admin)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$brickwork], [150]))->assertRedirect();
        $issued = WorkOrder::query()->where('status', WorkOrder::DRAFT)->firstOrFail();
        $this->actingAs($admin)->post(route('projects.work-orders.submit', [$project, $issued]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.approve', [$project, $issued]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.issue', [$project, $issued]))->assertRedirect();
        $issued->refresh();
        $this->assertSame(WorkOrder::ISSUED, $issued->status);
        $this->assertSame(WorkOrder::NOT_STARTED, $issued->work_status);

        $vendor->update(['name' => 'Changed Name']);
        $this->assertSame('Sharma Contractors', $issued->fresh()->contractor_name);

        $this->actingAs($admin)
            ->put(route('projects.work-orders.update', [$project, $issued]), $this->payload($vendor, [$brickwork], [10]))
            ->assertSessionHasErrors('status');
        $this->assertSame('150.000', (string) $issued->fresh()->items()->first()->quantity);

        $this->actingAs($admin)->post(route('projects.work-orders.amend', [$project, $issued]))->assertRedirect();
        $amendment = WorkOrder::query()->where('amended_from_id', $issued->id)->firstOrFail();
        $this->assertSame(WorkOrder::DRAFT, $amendment->status);
        $this->assertSame(WorkOrder::ISSUED, $issued->fresh()->status);

        $amendmentPayload = $this->payload($vendor, [$brickwork], [80]);
        $this->actingAs($admin)->put(route('projects.work-orders.update', [$project, $amendment]), $amendmentPayload)->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.submit', [$project, $amendment]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.approve', [$project, $amendment]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.work-orders.issue', [$project, $amendment]))->assertRedirect();

        $this->assertSame(WorkOrder::SUPERSEDED, $issued->fresh()->status);
        $this->assertSame(WorkOrder::ISSUED, $amendment->fresh()->status);
        $this->assertSame('80.000', (string) $amendment->fresh()->items()->first()->quantity);

        $this->actingAs($admin)->post(route('projects.work-orders.execute', [$project, $amendment]), [
            'lines' => [[
                'id' => $amendment->items()->first()->id,
                'executed_quantity' => 40,
                'execution_status' => 'in_progress',
            ]],
        ])->assertRedirect();
        $amendment->refresh();
        $this->assertSame(WorkOrder::IN_PROGRESS, $amendment->status);
        $this->assertSame('40.000', (string) $amendment->items()->first()->executed_quantity);
        $this->assertSame('80.000', (string) $amendment->items()->first()->quantity);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.work-orders.execute', [$project, $amendment]), [
                'lines' => [[
                    'id' => $amendment->items()->first()->id,
                    'executed_quantity' => 90,
                    'execution_status' => 'in_progress',
                ]],
            ])
            ->assertForbidden();

        $this->actingAs($admin)->post(route('projects.work-orders.cancel', [$project, $amendment]), [
            'cancellation_reason' => 'Contractor withdrew',
        ])->assertRedirect();
        $this->assertSame(WorkOrder::CANCELLED, $amendment->fresh()->status);

        $this->actingAs($admin)
            ->get(route('projects.work-orders.show', [$project, $issued]))
            ->assertOk();
        $this->assertTrue(\App\Models\ActivityLog::query()
            ->where('subject_type', WorkOrder::class)
            ->where('subject_id', $issued->id)
            ->where('action', 'work_order.superseded')
            ->exists());
    }

    public function test_role_and_individual_permissions_are_enforced(): void
    {
        [$admin, $project] = $this->scene();
        $approverRole = Role::query()->where('slug', 'purchase_approver')->firstOrFail();
        $viewerRole = Role::query()->where('slug', 'viewer')->firstOrFail();
        $blocked = User::factory()->create(['role_id' => $approverRole->id, 'is_admin' => false]);
        $viewer = User::factory()->create(['role_id' => $viewerRole->id, 'is_admin' => false]);

        $this->actingAs($blocked)->get(route('projects.work-orders.index', $project))->assertForbidden();
        $this->actingAs($blocked)->post(route('projects.work-orders.store', $project), [])->assertForbidden();

        UserPermissionOverride::query()->create([
            'user_id' => $blocked->id,
            'permission' => 'work_orders.view',
            'effect' => UserPermissionOverride::ALLOW,
        ]);
        $blocked = $blocked->fresh();
        $this->actingAs($blocked)->get(route('projects.work-orders.index', $project))->assertOk();
        $this->actingAs($blocked)->post(route('projects.work-orders.store', $project), [])->assertForbidden();

        $this->actingAs($viewer)->get(route('projects.work-orders.index', $project))->assertOk();
        UserPermissionOverride::query()->create([
            'user_id' => $viewer->id,
            'permission' => 'work_orders.view',
            'effect' => UserPermissionOverride::DENY,
        ]);
        $viewer = $viewer->fresh();
        $this->actingAs($viewer)->get(route('projects.work-orders.index', $project))->assertForbidden();
        $this->actingAs($admin)->get(route('projects.work-orders.index', $project))->assertOk();
    }

    public function test_historical_boq_revision_link_is_preserved(): void
    {
        [$user, $project, $vendor, $items, $boq] = $this->scene();
        $this->actingAs($user)->post(route('projects.work-orders.store', $project), $this->payload($vendor, [$items[0]], [25]))->assertRedirect();
        $order = WorkOrder::query()->firstOrFail();
        $originalId = $items[0]->id;
        $originalRevision = $items[0]->boq_revision_id;

        $boq->currentRevision->update(['status' => BoqRevision::FINALIZED]);
        $next = app(BoqRevisionService::class)->createNext($boq->fresh(), $user, 'Rate change');
        $copy = $next->items()->where('description', 'Brickwork')->firstOrFail();

        $this->assertNotSame($originalId, $copy->id);
        $this->assertSame($originalId, $order->fresh()->items()->first()->boq_item_id);
        $this->assertSame($originalRevision, $order->fresh()->items()->first()->boq_revision_id);

        $response = $this->actingAs($user)->get(route('projects.work-orders.create', $project));
        $response->assertOk();
        $ids = collect($response->original->getData()['page']['props']['catalog'])->pluck('id');
        $this->assertTrue($ids->contains($originalId));
        $this->assertFalse($ids->contains($copy->id));
    }

    public function test_pdf_uses_company_branding(): void
    {
        [$user, $project, $vendor, $items] = $this->scene();
        CompanySetting::query()->create([
            'company_name' => 'HSB Works',
            'address' => 'Site Office',
            'gst_number' => '24AAAAA0000A1Z5',
        ]);
        $this->actingAs($user)->post(route('projects.work-orders.store', $project), $this->payload($vendor, $items))->assertRedirect();
        $order = WorkOrder::query()->firstOrFail();

        $response = $this->actingAs($user)->get(route('projects.work-orders.pdf', [$project, $order]));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $show = $this->actingAs($user)->get(route('projects.work-orders.show', [$project, $order]));
        $document = $show->original->getData()['page']['props']['workOrder'];
        $html = view('pdf.work-order', app(\App\Services\DocumentPdf::class)->viewData([
            'title' => 'WORK ORDER',
            'number' => $order->work_order_number,
            'revision' => null,
            'subject' => $project->project_name,
            'dateLabel' => 'Work order date',
            'date' => \App\Support\PdfFormat::date($document['work_order_date']),
            'status' => $document['status_label'],
            'party' => [
                'label' => 'Contractor',
                'name' => $document['contractor_name'],
                'lines' => [['text' => (string) $document['contractor_address']]],
            ],
            'relatedLabel' => 'Project',
            'relatedValue' => $project->project_code,
            'footerNote' => 'Work order quantities are contractor allocations and are not material receipts.',
            'projectName' => $project->project_name,
            'document' => $document,
        ]))->render();

        $this->assertStringContainsString('HSB Works', $html);
        $this->assertStringContainsString($order->work_order_number, $html);
        $this->assertStringContainsString('Sharma Contractors', $html);
        $this->assertStringContainsString('Brickwork', $html);
        $this->assertStringContainsString('305000.00', str_replace(',', '', $html));

        $download = $this->actingAs($user)->get(route('projects.work-orders.pdf.download', [$project, $order]));
        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
    }

    public function test_work_orders_do_not_affect_procurement_inventory_or_vendor_balances(): void
    {
        [$user, $project, $vendor, $items] = $this->scene();
        $this->actingAs($user)->post(route('projects.work-orders.store', $project), $this->payload($vendor, $items))->assertRedirect();
        $order = WorkOrder::query()->firstOrFail();
        $this->actingAs($user)->post(route('projects.work-orders.submit', [$project, $order]))->assertRedirect();
        $this->actingAs($user)->post(route('projects.work-orders.approve', [$project, $order]))->assertRedirect();
        $this->actingAs($user)->post(route('projects.work-orders.issue', [$project, $order]))->assertRedirect();

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, MaterialReceipt::query()->count());
        $this->assertSame(0, PurchaseInvoice::query()->count());
        $this->assertSame(0, VendorPayment::query()->count());
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame('0.00', (string) $vendor->fresh()->opening_advance);
        $this->assertSame('0.00', (string) $vendor->fresh()->opening_outstanding);
        $progress = app(BoqReceiptProgress::class)->acceptedFor([$items[0]->id]);
        $this->assertArrayNotHasKey($items[0]->id, $progress);

        $this->actingAs($user)
            ->get(route('projects.boqs.items.procurement', [$project, $items[0]->boq_id, $items[0]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrderSummary.allocated_quantity', '1000.000')
                ->where('receiptSummary.accepted_quantity', '0.000')
                ->has('workOrders', 1)
            );
    }

    /**
     * @param  list<BoqItem>  $items
     * @param  list<int|string>|null  $quantities
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor, array $items, ?array $quantities = null): array
    {
        return [
            'vendor_id' => $vendor->id,
            'work_order_date' => '2026-10-12',
            'completion_date' => '2026-12-31',
            'gst_percentage' => 0,
            'scope' => 'Civil finishes',
            'payment_terms' => '30 days',
            'terms_conditions' => 'As per the contract',
            'items' => array_map(function (BoqItem $item, int $index) use ($quantities) {
                return [
                    'boq_item_id' => $item->id,
                    'quantity' => $quantities[$index] ?? $item->quantity,
                    'rate' => $item->rate,
                ];
            }, $items, array_keys($items)),
        ];
    }

    /**
     * @return array{0: User, 1: Project, 2: Vendor, 3: list<BoqItem>, 4: Boq}
     */
    private function scene(): array
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $vendor = Vendor::factory()->create([
            'party_type' => Vendor::CONTRACTOR,
            'name' => 'Sharma Contractors',
            'contact_person' => 'Amit Sharma',
            'status' => Vendor::ACTIVE,
        ]);
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $specs = [
            ['Brickwork', '1000', '100', 'SQFT', 'BW'],
            ['Plastering', '2000', '35', 'SQFT', 'PL'],
            ['Flooring', '500', '120', 'SQFT', 'FL'],
            ['Painting', '3000', '25', 'SQFT', 'PT'],
        ];
        $items = [];

        foreach ($specs as $index => [$description, $quantity, $rate, $unit, $code]) {
            $items[] = BoqItem::factory()->create([
                'boq_id' => $boq->id,
                'item_number' => (string) ($index + 1),
                'service_code' => $code,
                'description' => $description,
                'unit' => $unit,
                'quantity' => $quantity,
                'rate' => $rate,
                'sort_order' => $index,
            ]);
        }

        return [$user, $project, $vendor, $items, $boq];
    }
}
