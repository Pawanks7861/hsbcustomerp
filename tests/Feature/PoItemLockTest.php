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
use Tests\TestCase;

class PoItemLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_items_are_copied_from_the_approved_request_and_cannot_be_changed(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        [$project, $boq, $item] = $this->procurable();
        $other = Project::factory()->create();

        $draft = $this->makeRequest($user, $project, $boq, $item);
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$project, $draft]), $this->header())
            ->assertForbidden();

        $draft->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$project, $draft]), $this->header())
            ->assertForbidden();

        $approved = $this->makeRequest($user, $project, $boq, $item);
        $approved->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$other, $approved]), $this->header())
            ->assertNotFound();

        $vendor = Vendor::factory()->create([
            'name' => 'Locked Supplier',
            'address' => 'Old address',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.purchase-orders.store', [$project, $approved]), $this->header($vendor, [
                'items' => [
                    [
                        'description' => 'Added by the browser',
                        'service_code' => 'HACK',
                        'unit' => 'Ton',
                        'quantity' => 99,
                        'rate' => 50,
                        'amount' => 1,
                        'remarks' => 'Changed',
                    ],
                    [
                        'description' => 'Second manual line',
                        'quantity' => 1,
                        'rate' => 1,
                    ],
                ],
            ]))->assertRedirect();

        $order = PurchaseOrder::query()->firstOrFail();
        $order->load('items');
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertCount(1, $order->items);
        $line = $order->items[0];
        $this->assertSame('Cement putty', $line->description);
        $this->assertSame('90130546', $line->service_code);
        $this->assertSame('SQM', $line->unit);
        $this->assertSame('2.000', (string) $line->quantity);
        $this->assertSame('110.00', (string) $line->rate);
        $this->assertSame('220.00', (string) $line->amount);
        $this->assertSame('From the request', $line->remarks);
        $this->assertSame('220.00', (string) $order->subtotal);
        $this->assertSame('Locked Supplier', $order->supplier_name);

        $revised = Vendor::factory()->create([
            'name' => 'Revised Supplier',
            'address' => 'Site road',
            'phone' => '9999999999',
            'email' => 'supplier@example.com',
            'gst_number' => '24AAAAA0000A1Z5',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->header($revised, [
                'delivery_date' => '2026-11-01',
                'delivery_address' => 'Bapunagar',
                'gst_percentage' => 18,
                'terms_conditions' => 'Net 30',
                'remarks' => 'Header only',
                'items' => [[
                    'description' => 'Edited description',
                    'service_code' => '999',
                    'unit' => 'Nos',
                    'quantity' => 8,
                    'rate' => 4,
                    'amount' => 32,
                ]],
            ]))->assertRedirect();

        $order->refresh()->load('items');
        $this->assertSame('draft', $order->status);
        $this->assertSame('Revised Supplier', $order->supplier_name);
        $this->assertSame('Revised Supplier', $order->vendor_name_snapshot);
        $this->assertSame('Site road', $order->vendor_address_snapshot);
        $this->assertSame('9999999999', $order->vendor_phone_snapshot);
        $this->assertSame('supplier@example.com', $order->vendor_email_snapshot);
        $this->assertSame('24AAAAA0000A1Z5', $order->vendor_gst_snapshot);
        $this->assertSame('2026-11-01', $order->delivery_date?->toDateString());
        $this->assertSame('Bapunagar', $order->delivery_address);
        $this->assertSame('18.00', (string) $order->gst_percentage);
        $this->assertSame('39.60', (string) $order->gst_amount);
        $this->assertSame('259.60', (string) $order->grand_total);
        $this->assertSame('Net 30', $order->terms_conditions);
        $this->assertSame('Header only', $order->remarks);
        $this->assertCount(1, $order->items);
        $this->assertSame('Cement putty', $order->items[0]->description);
        $this->assertSame('90130546', $order->items[0]->service_code);
        $this->assertSame('2.000', (string) $order->items[0]->quantity);
        $this->assertSame('110.00', (string) $order->items[0]->rate);
        $this->assertSame('220.00', (string) $order->items[0]->amount);

        $this->actingAs($user)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->header($revised, [
                'cgst_percentage' => 9,
                'sgst_percentage' => 9,
                'freight' => 10,
                'round_off' => -0.04,
                'place_of_supply' => 'Gujarat',
                'items' => [[
                    'description' => 'Should stay locked',
                    'quantity' => 50,
                    'rate' => 1,
                    'hsn_sac' => '2523',
                    'discount_percentage' => 10,
                ]],
            ]))->assertRedirect();

        $order->refresh()->load('items');
        $this->assertSame('Cement putty', $order->items[0]->description);
        $this->assertSame('2.000', (string) $order->items[0]->quantity);
        $this->assertSame('110.00', (string) $order->items[0]->rate);
        $this->assertSame('220.00', (string) $order->items[0]->amount);
        $this->assertSame('2523', $order->items[0]->hsn_sac);
        $this->assertSame('10.00', (string) $order->items[0]->discount_percentage);
        $this->assertSame('22.00', (string) $order->items[0]->discount_amount);
        $this->assertSame('198.00', (string) $order->items[0]->taxable_amount);
        $this->assertSame('17.82', (string) $order->items[0]->cgst_amount);
        $this->assertSame('17.82', (string) $order->items[0]->sgst_amount);
        $this->assertSame('Gujarat', $order->place_of_supply);
        $this->assertSame('9.00', (string) $order->cgst_percentage);
        $this->assertSame('9.00', (string) $order->sgst_percentage);
        $this->assertSame('18.00', (string) $order->gst_percentage);
        $this->assertSame('35.64', (string) $order->gst_amount);
        $this->assertSame('10.00', (string) $order->freight);
        $this->assertSame('-0.04', (string) $order->round_off);
        $this->assertSame('243.60', (string) $order->grand_total);

        $this->actingAs($user)
            ->post(route('projects.purchase-orders.submit', [$project, $order]))
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('projects.purchase-orders.approve', [$project, $order]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->put(route('projects.purchase-orders.update', [$project, $order]), $this->header($revised, [
                'supplier_name' => 'Should stay locked',
                'items' => [[
                    'description' => 'Approved edit',
                    'quantity' => 1,
                    'rate' => 1,
                ]],
            ]))->assertForbidden();

        $order->refresh()->load('items');
        $this->assertSame('approved', $order->status);
        $this->assertSame('Revised Supplier', $order->supplier_name);
        $this->assertSame('Cement putty', $order->items[0]->description);
        $this->assertSame('220.00', (string) $order->items[0]->amount);
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
            'rate' => 110,
            'unit' => 'SQM',
        ]);

        return [$project, $boq, $item];
    }

    private function makeRequest(User $user, Project $project, Boq $boq, BoqItem $item): PurchaseRequest
    {
        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), [
                'request_date' => '2026-10-08',
                'description' => 'Finishes',
                'requested_boq_quantity' => '2.000',
                'items' => [[
                    'description' => 'Cement putty',
                    'service_code' => '90130546',
                    'unit' => 'SQM',
                    'quantity' => 2,
                    'estimated_rate' => 110,
                    'remarks' => 'From the request',
                ]],
            ])->assertRedirect();

        return PurchaseRequest::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function header(?Vendor $vendor = null, array $extra = []): array
    {
        $vendor ??= Vendor::factory()->create(['name' => 'Local Supplier', 'status' => 'active']);

        return [
            'po_date' => '2026-10-08',
            'vendor_id' => $vendor->id,
            'gst_percentage' => 0,
            ...$extra,
        ];
    }
}
