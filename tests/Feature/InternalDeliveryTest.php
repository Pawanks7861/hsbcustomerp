<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CompanySetting;
use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Services\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_delivery_moves_stock_once_and_records_partial_and_short_quantities(): void
    {
        $admin = User::factory()->admin()->create();
        $source = Project::factory()->create();
        $destination = Project::factory()->create();
        $material = Material::query()->create([
            'material_code' => 'MAT-000010',
            'name' => 'Cement',
            'unit' => 'Bag',
            'is_active' => true,
        ]);
        $from = InventoryLocation::query()->where('project_id', $source->id)->where('is_default', true)->firstOrFail();
        $to = InventoryLocation::query()->where('project_id', $destination->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $source), [
            'material_id' => $material->id,
            'adjustment_date' => '2026-10-12',
            'adjustment_type' => 'opening',
            'quantity' => 10,
            'reason' => 'Opening stock',
        ])->assertRedirect();
        $adjustment = $source->stockAdjustments()->firstOrFail();
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.post', [$source, $adjustment]))->assertRedirect();

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $from->id,
            'items' => [['material_id' => $material->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('destination_location_id');

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'vehicle_number' => 'GJ01AB1234',
            'items' => [['material_id' => $material->id, 'quantity' => 4]],
        ])->assertRedirect();

        $note = InternalDeliveryNote::query()->firstOrFail();
        $this->assertSame('draft', $note->status);
        $this->assertSame('10.000', app(InventoryStock::class)->balance($source->id, $material->id));

        $this->actingAs($admin)->put(route('inventory.transfers.update', $note), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'items' => [['material_id' => $material->id, 'quantity' => 6]],
        ])->assertRedirect();
        $this->assertSame('6.000', $note->fresh()->items()->first()->quantity);

        $outsider = User::factory()->create([
            'role_id' => \App\Models\Role::query()->where('slug', 'storekeeper')->value('id'),
            'project_access' => 'selected',
        ]);
        $outsider->projects()->sync([$destination->id]);
        $this->actingAs($outsider)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'items' => [['material_id' => $material->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->actingAs($admin)->post(route('inventory.transfers.submit', $note))->assertRedirect();
        $this->actingAs($admin)->post(route('inventory.transfers.approve', $note))->assertRedirect();
        $this->actingAs($admin)->post(route('inventory.transfers.dispatch', $note))->assertRedirect();
        $this->actingAs($admin)->post(route('inventory.transfers.dispatch', $note))->assertForbidden();

        $this->assertSame('4.000', app(InventoryStock::class)->balance($source->id, $material->id));
        $this->assertSame('0.000', app(InventoryStock::class)->balance($destination->id, $material->id));
        $transit = InventoryLocation::query()->where('location_code', 'IN-TRANSIT')->firstOrFail();
        $this->assertSame('6.000', app(InventoryStock::class)->locationBalance($source->id, $material->id, $transit->id));
        $this->assertSame(1, StockLedgerEntry::query()->where('source_type', 'idn_dispatch')->count());

        $item = $note->fresh()->items()->firstOrFail();
        $this->actingAs($admin)->post(route('inventory.transfers.receive', $note), [
            'items' => [[
                'item_id' => $item->id,
                'quantity_received' => 4,
                'quantity_damaged' => 0,
                'quantity_short' => 0,
            ]],
        ])->assertRedirect();
        $this->assertSame('partially_received', $note->fresh()->status);
        $this->assertSame('4.000', app(InventoryStock::class)->balance($destination->id, $material->id));
        $this->assertSame('2.000', app(InventoryStock::class)->locationBalance($source->id, $material->id, $transit->id));

        $this->actingAs($admin)->post(route('inventory.transfers.receive', $note), [
            'items' => [[
                'item_id' => $item->id,
                'quantity_received' => 3,
                'quantity_damaged' => 0,
                'quantity_short' => 0,
            ]],
        ])->assertSessionHasErrors('items');

        $this->actingAs($admin)->post(route('inventory.transfers.receive', $note), [
            'items' => [[
                'item_id' => $item->id,
                'quantity_received' => 0,
                'quantity_damaged' => 1,
                'quantity_short' => 1,
            ]],
        ])->assertRedirect();
        $this->assertSame('received', $note->fresh()->status);
        $this->assertSame('4.000', app(InventoryStock::class)->balance($destination->id, $material->id));
        $this->assertSame('0.000', app(InventoryStock::class)->locationBalance($source->id, $material->id, $transit->id));
        $this->assertSame('1.000', $item->fresh()->quantity_damaged);
        $this->assertSame('1.000', $item->fresh()->quantity_short);
        $this->assertTrue(ActivityLog::query()->where('action', 'idn.dispatched')->exists());

        $second = $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'items' => [['material_id' => $material->id, 'quantity' => 5]],
        ]);
        $second->assertRedirect();
        $other = InternalDeliveryNote::query()->whereKeyNot($note->id)->firstOrFail();
        $this->actingAs($admin)->post(route('inventory.transfers.submit', $other));
        $this->actingAs($admin)->post(route('inventory.transfers.approve', $other));
        $this->actingAs($admin)->post(route('inventory.transfers.dispatch', $other))->assertSessionHasErrors('items');
        $this->assertSame('4.000', app(InventoryStock::class)->balance($source->id, $material->id));
    }

    public function test_dispatched_transfer_can_be_reversed_and_pdf_is_authorized(): void
    {
        [$admin, $note, $source, $material] = $this->dispatched(2);
        CompanySetting::query()->create(['company_name' => 'Acme Construction']);

        $this->actingAs($admin)->post(route('inventory.transfers.cancel', $note), [
            'reason' => 'Wrong site',
        ])->assertRedirect();
        $this->assertSame('cancelled', $note->fresh()->status);
        $this->assertSame('2.000', app(InventoryStock::class)->balance($source->id, $material->id));
        $this->assertTrue(StockLedgerEntry::query()->where('source_type', 'reversal')->exists());

        [$admin, $printed] = array_slice($this->dispatched(1), 0, 2);
        $this->actingAs($admin)->get(route('inventory.transfers.pdf', $printed))->assertOk();
        $this->actingAs($admin)->get(route('inventory.transfers.pdf.download', $printed))
            ->assertOk()
            ->assertHeader('content-disposition');

        $stranger = User::factory()->create([
            'role_id' => \App\Models\Role::query()->where('slug', 'viewer')->value('id'),
            'project_access' => 'selected',
        ]);
        $this->actingAs($stranger)->get(route('inventory.transfers.pdf', $printed))->assertNotFound();
    }

    /**
     * @return array{0: User, 1: InternalDeliveryNote, 2: Project, 3: Material}
     */
    private function dispatched(int $quantity): array
    {
        $admin = User::factory()->admin()->create();
        $source = Project::factory()->create();
        $destination = Project::factory()->create();
        $material = Material::query()->create([
            'material_code' => 'MAT-'.random_int(100000, 999999),
            'name' => 'Sand',
            'unit' => 'Cum',
            'is_active' => true,
        ]);
        $from = InventoryLocation::query()->where('project_id', $source->id)->where('is_default', true)->firstOrFail();
        $to = InventoryLocation::query()->where('project_id', $destination->id)->where('is_default', true)->firstOrFail();
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $source), [
            'material_id' => $material->id,
            'adjustment_date' => '2026-10-12',
            'adjustment_type' => 'opening',
            'quantity' => $quantity,
            'reason' => 'Opening',
        ]);
        $adjustment = $source->stockAdjustments()->firstOrFail();
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.post', [$source, $adjustment]));
        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-12',
            'source_location_id' => $from->id,
            'destination_location_id' => $to->id,
            'items' => [['material_id' => $material->id, 'quantity' => $quantity]],
        ]);
        $note = InternalDeliveryNote::query()->latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('inventory.transfers.submit', $note));
        $this->actingAs($admin)->post(route('inventory.transfers.approve', $note));
        $this->actingAs($admin)->post(route('inventory.transfers.dispatch', $note))->assertRedirect();

        return [$admin, $note, $source, $material];
    }
}
