<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqItemBudget;
use App\Models\BoqRevision;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoqRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_boq_is_revision_zero_and_new_revisions_keep_history(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'title' => 'Finishes']);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '10',
            'description' => 'Putty',
            'unit' => 'SQM',
            'quantity' => 10,
            'rate' => 100,
        ]);
        $item->budgets()->create(['name' => 'Material', 'amount' => 250, 'sort_order' => 0]);

        $original = $boq->revisions()->first();
        $this->assertSame(0, $original->revision_number);
        $this->assertSame(BoqRevision::DRAFT, $original->status);
        $this->assertSame($original->id, $item->fresh()->boq_revision_id);
        $this->assertSame('1000.00', (string) $item->fresh()->amount);

        $this->actingAs($user)
            ->post(route('projects.boqs.revisions.store', [$project, $boq]))
            ->assertSessionHasErrors('revision');

        $this->actingAs($user)
            ->post(route('projects.boqs.revisions.finalize', [$project, $boq, $original]))
            ->assertRedirect();
        $this->assertSame(BoqRevision::FINALIZED, $original->fresh()->status);

        $this->actingAs($user)
            ->post(route('projects.boqs.revisions.store', [$project, $boq]), [
                'revision_reason' => 'Rate change',
            ])->assertRedirect();

        $draft = $boq->revisions()->where('revision_number', 1)->firstOrFail();
        $this->assertSame(BoqRevision::DRAFT, $draft->status);
        $this->assertSame($original->id, $boq->fresh()->current_revision_id);
        $copy = $draft->items()->first();
        $this->assertNotSame($item->id, $copy->id);
        $this->assertSame('Putty', $copy->description);
        $this->assertSame('1000.00', (string) $copy->amount);
        $this->assertSame('250.00', (string) $copy->budgets()->first()->amount);

        $this->actingAs($user)
            ->put(route('projects.boqs.update', [$project, $boq]), [
                'revision_id' => $draft->id,
                'title' => $boq->title,
                'gst_percentage' => 0,
                'items' => [[
                    'id' => $copy->id,
                    'client_key' => 'copy',
                    'parent_key' => null,
                    'item_number' => '10',
                    'description' => 'Putty revised',
                    'unit' => 'SQM',
                    'quantity' => 10,
                    'rate' => 125,
                ]],
            ])->assertRedirect();

        $this->assertSame('Putty', $item->fresh()->description);
        $this->assertSame('1000.00', (string) $item->fresh()->amount);
        $this->assertSame('Putty revised', $copy->fresh()->description);
        $this->assertSame('1250.00', (string) $copy->fresh()->amount);
        $this->assertSame('250.00', (string) $item->budgets()->first()->amount);

        $this->actingAs($user)
            ->put(route('projects.boqs.update', [$project, $boq]), [
                'revision_id' => $original->id,
                'title' => 'Changed history',
                'gst_percentage' => 0,
                'items' => [[
                    'id' => $item->id,
                    'client_key' => 'old',
                    'description' => 'Should not save',
                    'quantity' => 1,
                    'rate' => 1,
                ]],
            ])->assertSessionHasErrors('items');
        $this->assertSame('Putty', $item->fresh()->description);

        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $copy]), $this->requestPayload())
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), $this->requestPayload())
            ->assertRedirect();
        $historicalRequest = PurchaseRequest::query()->firstOrFail();
        $this->assertSame($item->id, $historicalRequest->boq_item_id);

        $this->actingAs($user)
            ->post(route('projects.boqs.revisions.finalize', [$project, $boq, $draft]))
            ->assertRedirect();
        $this->assertSame($draft->id, $boq->fresh()->current_revision_id);

        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), $this->requestPayload())
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $copy]), $this->requestPayload())
            ->assertRedirect();

        $this->assertSame($item->id, $historicalRequest->fresh()->boq_item_id);
        $this->assertSame(1, BoqItemBudget::query()->where('boq_item_id', $item->id)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(): array
    {
        return [
            'request_date' => '2026-10-09',
            'description' => 'Request',
            'requested_boq_quantity' => '1.000',
            'items' => [[
                'description' => 'Putty',
                'unit' => 'SQM',
                'quantity' => 1,
                'estimated_rate' => 100,
            ]],
        ];
    }
}
