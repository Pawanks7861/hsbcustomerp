<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\CompanySetting;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\BoqProcurementQuantityService;
use App\Services\BoqRevisionService;
use App\Services\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InventoryProcurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_purchase_request_keeps_each_boq_item_on_its_own_line(): void
    {
        $user = User::factory()->create();
        [$project, $first, $second] = $this->boqPair();

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'description' => 'Site requirement',
            'items' => [
                ['boq_item_id' => $first->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $single = PurchaseRequest::query()->first();
        $this->assertCount(1, $single->items);
        $this->assertSame($first->id, $single->items[0]->boq_item_id);

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'items' => [
                ['boq_item_id' => $first->id, 'quantity' => 3],
                ['boq_item_id' => $second->id, 'quantity' => 4],
            ],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->whereKeyNot($single->id)->firstOrFail();
        $request->load('items');
        $this->assertCount(2, $request->items);
        $this->assertSame(['3.000', '4.000'], $request->items->pluck('quantity')->map(fn ($qty) => (string) $qty)->all());
        $this->assertSame($first->description, $request->items[0]->description);
        $this->assertSame($second->unit, $request->items[1]->unit);
        $this->assertSame('400.00', (string) $request->items[1]->estimated_amount);

        $this->actingAs($user)->get(route('projects.purchase-requests.show', [$project, $request]))->assertOk();
        $reloaded = $request->fresh('items');
        $this->assertCount(2, $reloaded->items);

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'items' => [
                ['boq_item_id' => $first->id, 'quantity' => 1],
                ['boq_item_id' => $first->id, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('items.0.boq_item_id');

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'items' => [['quantity' => 1]],
        ])->assertSessionHasErrors('items.0.boq_item_id');

        $other = Project::factory()->create();
        $this->actingAs($user)->post(route('projects.purchase-requests.store', $other), [
            'request_date' => '2026-10-12',
            'items' => [['boq_item_id' => $first->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items.0.boq_item_id');
        $this->assertSame(0, PurchaseRequest::query()->where('project_id', $other->id)->count());
    }

    public function test_only_the_current_finalized_revision_can_be_requested_and_one_line_can_exceed(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        [$project, $first, $second] = $this->boqPair(10, 10);
        $boq = $first->boq;
        app(BoqRevisionService::class)->finalize($boq, $boq->currentRevision);
        $draft = app(BoqRevisionService::class)->createNext($boq, $user, 'Rates');
        $draftItem = BoqItem::query()->where('boq_revision_id', $draft->id)->firstOrFail();

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'items' => [['boq_item_id' => $draftItem->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items.0.boq_item_id');

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'items' => [
                ['boq_item_id' => $first->id, 'quantity' => 12],
                ['boq_item_id' => $second->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->latest('id')->first();
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $request]))->assertRedirect();
        $request->refresh()->load('items');
        $this->assertTrue($request->is_boq_quantity_exceeded);
        $this->assertTrue($request->items[0]->is_boq_quantity_exceeded);
        $this->assertFalse($request->items[1]->is_boq_quantity_exceeded);
        $this->assertSame('2.000', (string) $request->items[0]->excess_quantity);
        $this->assertSame('0.000', (string) $request->items[1]->excess_quantity);

        $this->actingAs($admin)->post(route('projects.purchase-requests.approve', [$project, $request]))->assertSessionHasErrors('over_quantity_approval_note');
        $this->actingAs($admin)->post(route('projects.purchase-requests.approve', [$project, $request]), [
            'over_quantity_approval_note' => 'Approved above BOQ',
        ])->assertRedirect();
        $this->assertSame('approved', $request->fresh()->status);

        $rejected = $this->multi($user, $project, $first, $second, 1, 1);
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $rejected]));
        $this->actingAs($admin)->post(route('projects.purchase-requests.reject', [$project, $rejected]), [
            'rejection_reason' => 'Not required',
        ])->assertRedirect();
        $summary = app(BoqProcurementQuantityService::class)->getSummary($second->fresh());
        $this->assertSame('2.000', $summary['approved_quantity']);
    }

    public function test_existing_single_item_requests_are_still_counted_once(): void
    {
        $user = User::factory()->create();
        [$project, $first] = $this->boqPair();
        $this->actingAs($user)->post(route('projects.boqs.items.purchase-requests.store', [$project, $first->boq, $first]), [
            'request_date' => '2026-10-12',
            'requested_boq_quantity' => 6,
            'items' => [['description' => 'Legacy line', 'unit' => 'Nos', 'quantity' => 1, 'estimated_rate' => 10]],
        ])->assertRedirect();
        $request = PurchaseRequest::query()->first();
        $this->assertNull($request->items()->first()->boq_item_id);
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $request]));
        $this->assertSame('6.000', app(BoqProcurementQuantityService::class)->getSummary($first->fresh())['used_quantity']);
    }

    public function test_purchase_order_copies_every_approved_line_and_keeps_lines_locked(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        [$project, $first, $second] = $this->boqPair();
        $request = $this->multi($user, $project, $first, $second, 2, 5);
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $request]));
        $this->actingAs($admin)->post(route('projects.purchase-requests.approve', [$project, $request]));
        $vendor = Vendor::factory()->create(['name' => 'Locked Vendor']);

        $this->actingAs($user)->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), [
            'po_date' => '2026-10-12',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
            'items' => [
                ['description' => 'Injected', 'quantity' => 99, 'rate' => 1],
                ['description' => 'Also injected', 'quantity' => 8, 'rate' => 2],
                ['description' => 'Third injected', 'quantity' => 1, 'rate' => 1],
            ],
        ])->assertRedirect();

        $order = PurchaseOrder::query()->firstOrFail();
        $order->load('items');
        $this->assertCount(2, $order->items);
        $this->assertSame($first->id, $order->items[0]->boq_item_id);
        $this->assertSame($second->id, $order->items[1]->boq_item_id);
        $this->assertSame('2.000', (string) $order->items[0]->quantity);
        $this->assertSame('5.000', (string) $order->items[1]->quantity);
        $this->assertSame($first->description, $order->items[0]->description);
        $this->assertSame('700.00', (string) $order->subtotal);
        $this->assertSame($vendor->name, $order->vendor_name_snapshot);

        $this->actingAs($user)->put(route('projects.purchase-orders.update', [$project, $order]), [
            'po_date' => '2026-10-12',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
            'items' => [['description' => 'Changed', 'quantity' => 1, 'rate' => 1]],
        ])->assertRedirect();
        $order->refresh()->load('items');
        $this->assertCount(2, $order->items);
        $this->assertSame($first->description, $order->items[0]->description);
        $this->assertSame('2.000', (string) $order->items[0]->quantity);
    }

    public function test_posted_grn_adds_only_accepted_stock_once_and_partial_receipts_stay_open(): void
    {
        [$user, $admin, $project, $order, $material] = $this->approvedOrder();
        $line = $order->items()->first();
        $service = $order->items()->skip(1)->first();

        $this->actingAs($user)->post(route('projects.store.store', $project), [
            'receipt_date' => '2026-10-12',
            'received_by' => 'Store keeper',
            'items' => [['description' => 'Manual', 'unit' => 'Bag', 'quantity_received' => 9]],
        ])->assertRedirect();
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame('legacy', MaterialReceipt::query()->first()->status);

        $this->actingAs($user)->post(route('projects.purchase-orders.grns.store', [$project, $order]), $this->grn([
            $this->grnLine($line, $material, '4', '3', '1'),
            $this->grnLine($service, null, '2', '2', '0'),
        ]))->assertRedirect();

        $grn = MaterialReceipt::query()->where('document_kind', 'grn')->first();
        $this->assertSame('draft', $grn->status);
        $this->assertSame(0, StockLedgerEntry::query()->count());

        $this->actingAs($user)->post(route('projects.grns.submit', [$project, $grn]))->assertRedirect();
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->actingAs($user)->post(route('projects.grns.post', [$project, $grn]))->assertForbidden();
        $this->actingAs($admin)->post(route('projects.grns.post', [$project, $grn]))->assertRedirect();
        $this->actingAs($admin)->post(route('projects.grns.post', [$project, $grn]))->assertForbidden();

        $this->assertSame(1, StockLedgerEntry::query()->count());
        $this->assertSame('3.000', app(InventoryStock::class)->balance($project->id, $material->id));
        $order->refresh();
        $this->assertSame('Partially Received', app(\App\Services\PoReceiptProgress::class)->label($order));

        $this->actingAs($user)->post(route('projects.purchase-orders.grns.store', [$project, $order]), $this->grn([
            $this->grnLine($line, $material, '8', '8', '0'),
        ]))->assertSessionHasErrors('items.0.quantity_received');

        $this->actingAs($user)->post(route('projects.purchase-orders.grns.store', [$project, $order]), $this->grn([
            $this->grnLine($line, $material, '5', '5', '0'),
        ]))->assertRedirect();
        $second = MaterialReceipt::query()->where('document_kind', 'grn')->where('status', 'draft')->first();
        $this->actingAs($user)->post(route('projects.grns.submit', [$project, $second]));
        $this->actingAs($admin)->post(route('projects.grns.post', [$project, $second]));
        $this->assertSame('8.000', app(InventoryStock::class)->balance($project->id, $material->id));
        $this->assertSame(2, StockLedgerEntry::query()->where('source_type', 'grn')->count());

        $other = Project::factory()->create();
        $this->actingAs($user)->get(route('projects.grns.show', [$other, $grn]))->assertNotFound();

        $rejected = $this->actingAs($user)->post(route('projects.purchase-orders.grns.store', [$project, $order]), $this->grn([
            $this->grnLine($service, null, '1', '1', '0'),
        ]));
        $rejected->assertRedirect();
        $pending = MaterialReceipt::query()->where('status', 'draft')->first();
        $this->actingAs($user)->post(route('projects.grns.submit', [$project, $pending]));
        $this->actingAs($admin)->post(route('projects.grns.reject', [$project, $pending]), [
            'rejection_reason' => 'Damaged delivery',
        ]);
        $this->assertSame('8.000', app(InventoryStock::class)->balance($project->id, $material->id));

        $this->actingAs($user)->post(route('projects.inventory.issues.store', $project), [
            'issue_date' => '2026-10-12',
            'issued_to' => 'Site engineer',
            'purpose' => 'Blockwork',
            'items' => [['material_id' => $material->id, 'quantity' => 20]],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->actingAs($user)->post(route('projects.inventory.issues.store', $project), [
            'issue_date' => '2026-10-12',
            'issued_to' => 'Site engineer',
            'purpose' => 'Blockwork',
            'items' => [['material_id' => $material->id, 'quantity' => 2]],
        ])->assertRedirect();
        $this->assertSame('8.000', app(InventoryStock::class)->balance($project->id, $material->id));
        $issue = \App\Models\MaterialIssue::query()->first();
        $this->actingAs($user)->post(route('projects.inventory.issues.post', [$project, $issue]))->assertForbidden();
        $this->actingAs($admin)->post(route('projects.inventory.issues.post', [$project, $issue]))->assertRedirect();
        $this->assertSame('6.000', app(InventoryStock::class)->balance($project->id, $material->id));

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $project), [
            'material_id' => $material->id,
            'adjustment_date' => '2026-10-12',
            'adjustment_type' => 'decrease',
            'quantity' => 1,
            'reason' => 'Count correction',
        ])->assertRedirect();
        $adjustment = \App\Models\StockAdjustment::query()->first();
        $this->assertSame('draft', $adjustment->status);
        $this->assertSame('6.000', app(InventoryStock::class)->balance($project->id, $material->id));
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.post', [$project, $adjustment]))->assertRedirect();
        $this->assertSame('5.000', app(InventoryStock::class)->balance($project->id, $material->id));
        $this->assertNotNull(StockLedgerEntry::query()->where('source_number', $adjustment->adjustment_number)->first());

        $material->update(['minimum_stock' => 10]);
        $this->actingAs($user)->get(route('projects.inventory.stock', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.0.status', 'Low Stock')->where('rows.0.available', '5.000'));

        $this->actingAs($admin)->post(route('projects.grns.cancel', [$project, $grn]))->assertRedirect();
        $this->assertSame('2.000', app(InventoryStock::class)->balance($project->id, $material->id));

        $this->actingAs($admin)->delete(route('projects.inventory.materials.destroy', [$project, $material]))
            ->assertSessionHasErrors('material');
        $this->assertNotNull($material->fresh());
    }

    public function test_grn_pdf_uses_the_shared_header_and_project_authorization(): void
    {
        [$user, $admin, $project, $order, $material] = $this->approvedOrder();
        CompanySetting::query()->create([
            'company_name' => 'Acme Construction',
            'address' => '14 Industrial Area',
        ]);
        $line = $order->items()->first();
        $this->actingAs($user)->post(route('projects.purchase-orders.grns.store', [$project, $order]), $this->grn([
            $this->grnLine($line, $material, '1', '1', '0'),
        ], ['challan_number' => 'CH-44']))->assertRedirect();
        $grn = MaterialReceipt::query()->where('document_kind', 'grn')->first();
        $this->actingAs($user)->post(route('projects.grns.submit', [$project, $grn]));
        $this->actingAs($admin)->post(route('projects.grns.post', [$project, $grn]));

        $view = $this->actingAs($user)->get(route('projects.grns.pdf', [$project, $grn]));
        $this->assertPdf($view, 'inline');
        $this->assertPdfContains($view, $grn->receipt_number);
        $this->assertPdfContains($view, 'Acme Construction');
        $this->assertPdfContains($view, 'GOODS RECEIPT NOTE');
        $this->assertPdfContains($view, $order->vendor_name_snapshot);
        $this->assertPdfContains($view, 'CH-44');

        $download = $this->actingAs($user)->get(route('projects.grns.pdf.download', [$project, $grn]));
        $this->assertPdf($download, 'attachment');
        $this->actingAs($user)->get(route('projects.grns.show', [$project, $grn]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receipt.receipt_number', $grn->receipt_number)
                ->has('receipt')
            );

        $this->actingAs($user)->get(route('projects.grns.pdf', [Project::factory()->create(), $grn]))->assertNotFound();
    }

    /**
     * @return array{0: Project, 1: BoqItem, 2: BoqItem}
     */
    private function boqPair(string $firstQty = '20', string $secondQty = '30'): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $first = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '1',
            'description' => 'Cement',
            'unit' => 'Bag',
            'quantity' => $firstQty,
            'rate' => 100,
        ]);
        $second = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '2',
            'description' => 'Labour',
            'unit' => 'Nos',
            'quantity' => $secondQty,
            'rate' => 100,
        ]);

        return [$project, $first, $second];
    }

    private function multi(User $user, Project $project, BoqItem $first, BoqItem $second, int $firstQty, int $secondQty): PurchaseRequest
    {
        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-12',
            'items' => [
                ['boq_item_id' => $first->id, 'quantity' => $firstQty],
                ['boq_item_id' => $second->id, 'quantity' => $secondQty],
            ],
        ])->assertRedirect();

        return PurchaseRequest::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array{0: User, 1: User, 2: Project, 3: PurchaseOrder, 4: Material}
     */
    private function approvedOrder(): array
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        [$project, $first, $second] = $this->boqPair();
        $request = $this->multi($user, $project, $first, $second, 10, 4);
        $this->actingAs($user)->post(route('projects.purchase-requests.submit', [$project, $request]));
        $this->actingAs($admin)->post(route('projects.purchase-requests.approve', [$project, $request]));
        $vendor = Vendor::factory()->create(['name' => 'Snapshot Supplies']);
        $this->actingAs($user)->post(route('projects.purchase-requests.purchase-orders.store', [$project, $request]), [
            'po_date' => '2026-10-12',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
        ])->assertRedirect();
        $order = PurchaseOrder::query()->firstOrFail();
        $this->actingAs($user)->post(route('projects.purchase-orders.submit', [$project, $order]));
        $this->actingAs($admin)->post(route('projects.purchase-orders.approve', [$project, $order]));
        $material = Material::query()->create([
            'material_code' => 'MAT-000001',
            'name' => 'Cement bags',
            'unit' => 'Bag',
            'minimum_stock' => 0,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        return [$user, $admin, $project, $order->fresh('items'), $material];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function grn(array $items, array $extra = []): array
    {
        return [
            'receipt_date' => '2026-10-12',
            'received_by' => 'Store keeper',
            'items' => $items,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grnLine(mixed $item, ?Material $material, string $received, string $accepted, string $rejected): array
    {
        return [
            'purchase_order_item_id' => $item->id,
            'material_id' => $material?->id,
            'quantity_received' => $received,
            'quantity_accepted' => $accepted,
            'quantity_rejected' => $rejected,
        ];
    }

    private function assertPdf(TestResponse $response, string $disposition): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString($disposition, (string) $response->headers->get('content-disposition'));
    }

    private function assertPdfContains(TestResponse $response, string $needle): void
    {
        $text = '';

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $response->getContent(), $matches)) {
            foreach ($matches[1] as $stream) {
                $chunk = @gzuncompress($stream);

                if (! is_string($chunk) || ! str_contains($chunk, 'TJ')) {
                    continue;
                }

                if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $chunk, $strings)) {
                    foreach ($strings[1] as $literal) {
                        $text .= str_replace("\0", '', stripcslashes($literal));
                    }
                }
            }
        }

        $this->assertTrue(str_contains($text, $needle), 'PDF missing '.$needle);
    }
}
