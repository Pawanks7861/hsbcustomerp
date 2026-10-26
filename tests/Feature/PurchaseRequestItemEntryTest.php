<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestItemEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pr_item_can_be_created_with_manual_name_and_no_boq_tag(): void
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-23',
            'items' => [[
                'name' => 'Site cement bags',
                'description' => 'OPC 53 for footing',
                'unit' => 'Bag',
                'quantity' => 12,
            ]],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->firstOrFail();
        $line = $request->items()->firstOrFail();

        $this->assertNull($request->boq_id);
        $this->assertNull($request->boq_item_id);
        $this->assertNull($line->boq_item_id);
        $this->assertSame('Site cement bags', $line->name);
        $this->assertSame('OPC 53 for footing', $line->description);
        $this->assertSame('Bag', $line->unit);
        $this->assertSame('12.000', (string) $line->quantity);
    }

    public function test_a_pr_item_can_keep_manual_details_when_an_optional_boq_tag_is_selected(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $item] = $this->taggedItem();

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-23',
            'items' => [[
                'name' => 'Custom site name',
                'description' => 'Custom site description',
                'unit' => 'Box',
                'quantity' => 4,
                'boq_item_id' => $item->id,
            ]],
        ])->assertRedirect();

        $line = PurchaseRequest::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame($item->id, $line->boq_item_id);
        $this->assertSame('Custom site name', $line->name);
        $this->assertSame('Custom site description', $line->description);
        $this->assertSame('Box', $line->unit);
        $this->assertNotSame($item->description, $line->name);
        $this->assertNotSame($item->description, $line->description);
    }

    public function test_existing_boq_linked_purchase_requests_still_load(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $item] = $this->taggedItem();

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-23',
            'items' => [['boq_item_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect();

        $request = PurchaseRequest::query()->firstOrFail();
        $this->actingAs($user)->get(route('projects.purchase-requests.show', [$project, $request]))->assertOk();
        $this->actingAs($user)->get(route('projects.purchase-requests.edit', [$project, $request]))->assertOk();
        $this->assertSame($item->id, $request->items()->first()->boq_item_id);
        $this->assertSame($item->description, $request->items()->first()->name);
    }

    public function test_boq_tags_from_another_project_are_rejected(): void
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();
        [, $foreign] = $this->taggedItem();

        $this->actingAs($user)->post(route('projects.purchase-requests.store', $project), [
            'request_date' => '2026-10-23',
            'items' => [[
                'name' => 'Manual item',
                'quantity' => 1,
                'boq_item_id' => $foreign->id,
            ]],
        ])->assertSessionHasErrors('items.0.boq_item_id');

        $this->assertSame(0, PurchaseRequest::query()->count());
    }

    /**
     * @return array{0: Project, 1: BoqItem}
     */
    private function taggedItem(): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '1.1',
            'description' => 'SecretBoqItemNameXYZ',
            'unit' => 'Bag',
            'quantity' => 20,
            'rate' => 100,
        ]);

        return [$project, $item];
    }
}
