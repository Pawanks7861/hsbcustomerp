<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\BoqProcurementQuantityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoqQuantityControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_is_within_exact_or_over_the_boq_quantity(): void
    {
        $user = User::factory()->admin()->create();
        $service = app(BoqProcurementQuantityService::class);

        [$project, $boq, $item] = $this->item('100');
        $this->submitted($user, $project, $boq, $item, '20');
        $within = $service->checkRequest($item->fresh(), '30');
        $this->assertSame('20.000', $within['previous_requested_quantity']);
        $this->assertSame('50.000', $within['cumulative_quantity']);
        $this->assertSame('0.000', $within['excess_quantity']);
        $this->assertFalse($within['is_exceeded']);

        [$project, $boq, $item] = $this->item('100');
        $this->submitted($user, $project, $boq, $item, '70');
        $exact = $service->checkRequest($item->fresh(), '30');
        $this->assertSame('100.000', $exact['cumulative_quantity']);
        $this->assertFalse($exact['is_exceeded']);

        [$project, $boq, $item] = $this->item('100');
        $this->submitted($user, $project, $boq, $item, '70');
        $over = $service->checkRequest($item->fresh(), '40');
        $this->assertSame('110.000', $over['cumulative_quantity']);
        $this->assertSame('10.000', $over['excess_quantity']);
        $this->assertTrue($over['is_exceeded']);
        $this->assertSame('30.000', $over['remaining_before_request']);
    }

    public function test_only_submitted_and_approved_quantities_are_counted(): void
    {
        $user = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $service = app(BoqProcurementQuantityService::class);

        $this->draft($user, $project, $boq, $item, '80');
        $this->assertSame('0.000', $service->getSummary($item->fresh())['used_quantity']);

        $rejected = $this->submitted($user, $project, $boq, $item, '40');
        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.reject', [$project, $rejected]), [
                'rejection_reason' => 'Not required',
            ])->assertRedirect();
        $this->assertSame('0.000', $service->getSummary($item->fresh())['used_quantity']);

        $this->submitted($user, $project, $boq, $item, '25');
        $summary = $service->getSummary($item->fresh());
        $this->assertSame('25.000', $summary['submitted_quantity']);
        $this->assertSame('25.000', $summary['used_quantity']);

        $approved = $this->submitted($user, $project, $boq, $item, '15');
        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.approve', [$project, $approved]))
            ->assertRedirect();
        $summary = $service->getSummary($item->fresh());
        $this->assertSame('25.000', $summary['submitted_quantity']);
        $this->assertSame('15.000', $summary['approved_quantity']);
        $this->assertSame('40.000', $summary['used_quantity']);
        $this->assertSame('60.000', $summary['remaining_quantity']);
    }

    public function test_current_purchase_request_is_excluded_from_existing_quantity(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->submitted($user, $project, $boq, $item, '40');
        $service = app(BoqProcurementQuantityService::class);

        $excluded = $service->checkRequest($item->fresh(), '40', $request);
        $this->assertSame('0.000', $excluded['previous_requested_quantity']);
        $this->assertSame('40.000', $excluded['cumulative_quantity']);
        $this->assertFalse($excluded['is_exceeded']);

        $included = $service->checkRequest($item->fresh(), '40');
        $this->assertSame('40.000', $included['previous_requested_quantity']);
        $this->assertSame('80.000', $included['cumulative_quantity']);
    }

    public function test_submission_stores_snapshots_and_flags_excess(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('140', 'Rmt');
        $this->submitted($user, $project, $boq, $item, '80');
        $approved = PurchaseRequest::query()->firstOrFail();
        $approved->update(['status' => 'approved']);

        $this->submitted($user, $project, $boq, $item, '40');
        $current = $this->draft($user, $project, $boq, $item, '30');

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $current]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $current->refresh();
        $this->assertTrue($current->is_boq_quantity_exceeded);
        $this->assertSame('140.000', (string) $current->boq_quantity_at_submission);
        $this->assertSame('120.000', (string) $current->previous_requested_quantity_at_submission);
        $this->assertSame('10.000', (string) $current->excess_quantity_at_submission);

        $item->update(['quantity' => 200]);
        $current->refresh();
        $this->assertSame('140.000', (string) $current->boq_quantity_at_submission);
        $this->assertSame('200.000', app(BoqProcurementQuantityService::class)->getSummary($item->fresh())['boq_quantity']);
    }

    public function test_normal_approval_does_not_require_an_excess_note(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->submitted($user, $project, $boq, $item, '30');

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.approve', [$project, $request]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertFalse($request->is_boq_quantity_exceeded);
        $this->assertNull($request->over_quantity_approved_by);
        $this->assertNull($request->over_quantity_approval_note);
    }

    public function test_over_quantity_approval_requires_a_note_and_records_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRoles('purchase_requester')->create();
        [$project, $boq, $item] = $this->item('100');
        $this->submitted($user, $project, $boq, $item, '70');
        $request = $this->submitted($user, $project, $boq, $item, '40');
        $this->assertTrue($request->is_boq_quantity_exceeded);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.approve', [$project, $request]), [
                'over_quantity_approval_note' => 'Should not be allowed',
            ])->assertForbidden();
        $this->assertSame('submitted', $request->fresh()->status);

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.approve', [$project, $request]))
            ->assertSessionHasErrors('over_quantity_approval_note');
        $this->assertSame('submitted', $request->fresh()->status);

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.approve', [$project, $request]), [
                'over_quantity_approval_note' => 'Approved due to revised site requirement pending BOQ amendment.',
            ])->assertRedirect();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->approved_by);
        $this->assertSame($admin->id, $request->over_quantity_approved_by);
        $this->assertNotNull($request->over_quantity_approved_at);
        $this->assertSame('Approved due to revised site requirement pending BOQ amendment.', $request->over_quantity_approval_note);
    }

    public function test_approved_requested_quantity_cannot_be_edited(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->submitted($user, $project, $boq, $item, '20');
        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.approve', [$project, $request]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->put(route('projects.purchase-requests.update', [$project, $request]), $this->payload('90'))
            ->assertForbidden();
        $this->assertSame('20.000', (string) $request->fresh()->requested_boq_quantity);
    }

    public function test_boq_item_without_quantity_cannot_submit_a_purchase_request(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->draft($user, $project, $boq, $item, '10');
        $item->update(['quantity' => null]);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $request]))
            ->assertSessionHasErrors([
                'requested_boq_quantity' => 'This BOQ item does not have a quantity defined.',
            ]);
        $this->assertSame('draft', $request->fresh()->status);
    }

    public function test_cross_project_submission_is_not_found(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->draft($user, $project, $boq, $item, '10');
        $other = Project::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$other, $request]))
            ->assertNotFound();
    }

    public function test_submission_locks_the_boq_item_inside_a_transaction(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->draft($user, $project, $boq, $item, '10');
        $lockedInsideTransaction = false;

        $this->partialMock(BoqProcurementQuantityService::class, function ($mock) use (&$lockedInsideTransaction) {
            $mock->shouldReceive('lockItemForUpdate')->once()->andReturnUsing(function (int $boqItemId) use (&$lockedInsideTransaction) {
                $lockedInsideTransaction = DB::transactionLevel() > 0;

                return BoqItem::query()->whereKey($boqItemId)->lockForUpdate()->firstOrFail();
            });
        });

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $request]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($lockedInsideTransaction);
        $this->assertSame('submitted', $request->fresh()->status);
    }

    public function test_rejected_request_can_be_revised_and_resubmitted_against_current_quantity(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('100');
        $request = $this->submitted($user, $project, $boq, $item, '120');
        $this->assertTrue($request->is_boq_quantity_exceeded);

        $this->actingAs($admin)
            ->post(route('projects.purchase-requests.reject', [$project, $request]), [
                'rejection_reason' => 'Reduce quantity',
            ])->assertRedirect();
        $this->assertSame('0.000', app(BoqProcurementQuantityService::class)->getSummary($item->fresh())['used_quantity']);

        $this->actingAs($user)
            ->post(route('projects.purchase-requests.reopen', [$project, $request]))
            ->assertRedirect();
        $this->actingAs($user)
            ->put(route('projects.purchase-requests.update', [$project, $request]), $this->payload('20'))
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $request]))
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('20.000', (string) $request->requested_boq_quantity);
        $this->assertFalse($request->is_boq_quantity_exceeded);
        $this->assertSame('0.000', (string) $request->excess_quantity_at_submission);
        $this->assertSame('100.000', (string) $request->boq_quantity_at_submission);
    }

    public function test_procurement_page_shows_the_quantity_summary(): void
    {
        $user = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        [$project, $boq, $item] = $this->item('140', 'Rmt');
        $approved = $this->submitted($user, $project, $boq, $item, '80');
        $this->actingAs($admin)->post(route('projects.purchase-requests.approve', [$project, $approved]));
        $this->submitted($user, $project, $boq, $item, '40');

        $this->actingAs($user)
            ->get(route('projects.boqs.items.procurement', [$project, $boq, $item]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('quantitySummary.boq_quantity', '140.000')
                ->where('quantitySummary.used_quantity', '120.000')
                ->where('quantitySummary.approved_quantity', '80.000')
                ->where('quantitySummary.remaining_quantity', '20.000')
            );

        $this->actingAs($user)
            ->get(route('projects.purchase-requests.index', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseRequests.data.0.quantity_control.state', 'within')
                ->where('purchaseRequests.data.1.quantity_control.state', 'within')
            );
    }

    /**
     * @return array{0: Project, 1: Boq, 2: BoqItem}
     */
    private function item(string $quantity, string $unit = 'Rmt'): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id]);
        $item = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'unit' => $unit,
            'quantity' => $quantity,
            'rate' => 10,
        ]);

        return [$project, $boq, $item];
    }

    private function draft(User $user, Project $project, Boq $boq, BoqItem $item, string $quantity): PurchaseRequest
    {
        $this->actingAs($user)
            ->post(route('projects.boqs.items.purchase-requests.store', [$project, $boq, $item]), $this->payload($quantity))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return PurchaseRequest::query()->orderByDesc('id')->firstOrFail();
    }

    private function submitted(User $user, Project $project, Boq $boq, BoqItem $item, string $quantity): PurchaseRequest
    {
        $request = $this->draft($user, $project, $boq, $item, $quantity);
        $this->actingAs($user)
            ->post(route('projects.purchase-requests.submit', [$project, $request]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $request->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $quantity): array
    {
        return [
            'request_date' => '2026-10-06',
            'requested_boq_quantity' => $quantity,
            'items' => [
                [
                    'description' => 'Cement',
                    'unit' => 'Bag',
                    'quantity' => 1,
                    'estimated_rate' => 10,
                ],
            ],
        ];
    }
}
