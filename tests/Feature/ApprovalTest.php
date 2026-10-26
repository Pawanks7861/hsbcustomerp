<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Access;
use App\Services\ApprovalInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_lists_only_documents_the_user_can_approve_in_their_projects(): void
    {
        $mine = Project::factory()->create(['project_code' => 'MINE']);
        $other = Project::factory()->create(['project_code' => 'OTHER']);
        $visible = $this->submittedRequest($mine, 'PR-2026-901');
        $this->submittedRequest($other, 'PR-2026-902');
        $this->draftAdjustment($mine);

        $approver = $this->userWithRole('purchase_approver', ['project_access' => 'selected']);
        $approver->projects()->attach($mine->id);
        $this->makeApprover($approver, ['purchase_requests', 'purchase_orders']);

        $this->actingAs($approver->fresh())->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Approvals/Index')
                ->where('approvals.pending', 1)
                ->where('approvals.can_approve', true)
                ->where('approvals.can_configure', false)
                ->has('items.data', 1)
                ->where('items.data.0.number', 'PR-2026-901')
                ->where('items.data.0.url', route('projects.purchase-requests.show', [$mine, $visible]))
                ->where('types', fn ($types) => collect($types)->pluck('key')->all() === ['purchase_requests', 'purchase_orders'])
            );

        $store = $this->userWithRole('store_manager');
        $this->makeApprover($store, ['stock_adjustments']);
        $this->actingAs($store->fresh())->get(route('approvals.index', ['type' => 'stock_adjustments']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.type', 'stock_adjustments')
                ->where('filters.type', 'stock_adjustments')
            );

        $viewer = $this->userWithRole('viewer');
        $this->actingAs($viewer)->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('approvals.can_approve', false)
                ->where('approvals.pending', 0)
                ->has('items.data', 0)
                ->has('types', 0)
            );
    }

    public function test_roles_no_longer_grant_approval_and_super_admins_always_approve(): void
    {
        $approverRole = Role::query()->where('slug', 'purchase_approver')->firstOrFail();
        $this->assertFalse($approverRole->grants('purchase_requests.approve'));
        RolePermission::query()->create(['role_id' => $approverRole->id, 'permission' => 'purchase_requests.approve']);

        $member = $this->userWithRole('purchase_approver');
        $access = app(Access::class);
        $this->assertFalse($access->allows($member, 'purchase_requests.approve'));
        $this->assertTrue($access->allows($member, 'purchase_requests.view'));

        $superAdmin = $this->userWithRole('super_admin');
        $flagAdmin = User::factory()->admin()->create();
        foreach (array_keys(ApprovalInbox::types()) as $type) {
            $permission = ApprovalInbox::types()[$type]['permissions'][0];
            $this->assertTrue($access->allows($superAdmin, $permission));
            $this->assertTrue($access->allows($flagAdmin, $permission));
        }
    }

    public function test_super_admin_and_admin_set_approvers_from_the_staff_list(): void
    {
        $superAdmin = User::factory()->admin()->create(['name' => 'Aaron Super']);
        $admin = $this->userWithRole('administrator');
        $manager = $this->userWithRole('purchase_manager');
        $engineer = $this->userWithRole('site_engineer');
        $keeper = $this->userWithRole('storekeeper');

        $this->actingAs($manager)->get(route('approvals.settings'))->assertForbidden();
        $this->actingAs($manager)->put(route('approvals.settings.update'), ['types' => []])->assertForbidden();

        $this->actingAs($admin)->get(route('approvals.settings'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Approvals/Settings')
                ->has('types', 7)
                ->where('staff', fn ($staff) => collect($staff)->firstWhere('id', $superAdmin->id)['super_admin'] === true)
                ->where('types.0.users', fn ($users) => collect($users)->contains($superAdmin->id) && ! collect($users)->contains($engineer->id))
                ->where('approvals.can_configure', true)
            );

        $this->actingAs($admin)->put(route('approvals.settings.update'), [
            'types' => [
                ['key' => 'purchase_requests', 'users' => [$engineer->id, $manager->id]],
                ['key' => 'grns', 'users' => [$keeper->id]],
            ],
        ])->assertRedirect(route('approvals.settings'));

        $access = app(Access::class);
        $this->assertTrue($access->allows($engineer->fresh(), 'purchase_requests.approve'));
        $this->assertTrue($access->allows($engineer->fresh(), 'purchase_requests.reject'));
        $this->assertTrue($access->allows($keeper->fresh(), 'grn.post'));
        $this->assertTrue($access->allows($keeper->fresh(), 'grn.reject'));
        $this->assertFalse($access->allows($keeper->fresh(), 'purchase_requests.approve'));

        $project = Project::factory()->create();
        $request = $this->submittedRequest($project, 'PR-2026-950');
        $this->actingAs($keeper->fresh())->post(route('projects.purchase-requests.approve', [$project, $request]))->assertForbidden();
        $this->actingAs($engineer->fresh())->post(route('projects.purchase-requests.approve', [$project, $request]))->assertRedirect();
        $this->assertSame('approved', $request->fresh()->status);

        $this->actingAs($superAdmin)->put(route('approvals.settings.update'), [
            'types' => [['key' => 'purchase_requests', 'users' => [$manager->id, $superAdmin->id]]],
        ])->assertRedirect();
        $this->assertFalse($access->allows($engineer->fresh(), 'purchase_requests.approve'));
        $this->assertSame(0, $engineer->permissionOverrides()->count());
        $this->assertTrue($access->allows($manager->fresh(), 'purchase_requests.approve'));
        $this->assertTrue($access->allows($keeper->fresh(), 'grn.post'));
        $this->assertSame(0, $superAdmin->permissionOverrides()->count());

        $this->actingAs($superAdmin)->put(route('users.permissions.update', $manager), [
            'overrides' => [
                ['permission' => 'users.view', 'mode' => 'allow'],
                ['permission' => 'purchase_requests.approve', 'mode' => 'inherit'],
            ],
        ])->assertRedirect();
        $this->assertTrue($access->allows($manager->fresh(), 'purchase_requests.approve'));
        $this->assertTrue($access->allows($manager->fresh(), 'users.view'));
    }

    public function test_settings_reject_unknown_document_types(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('approvals.settings.update'), [
            'types' => [['key' => 'payments', 'users' => []]],
        ])->assertSessionHasErrors('types.0.key');
    }

    /**
     * @param  list<string>  $types
     */
    private function makeApprover(User $user, array $types): void
    {
        foreach ($types as $type) {
            foreach (ApprovalInbox::types()[$type]['permissions'] as $permission) {
                UserPermissionOverride::query()->create([
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'effect' => UserPermissionOverride::ALLOW,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $slug, array $attributes = []): User
    {
        return User::factory()->withRoles($slug)->create($attributes);
    }

    private function submittedRequest(Project $project, string $number): PurchaseRequest
    {
        $boq = Boq::factory()->create(['project_id' => $project->id]);
        $item = BoqItem::factory()->create(['boq_id' => $boq->id, 'unit' => 'Bag', 'quantity' => 10]);

        $request = PurchaseRequest::query()->create([
            'project_id' => $project->id,
            'boq_id' => $boq->id,
            'boq_item_id' => $item->id,
            'pr_number' => $number,
            'request_date' => '2026-10-12',
            'requested_by' => User::factory()->create()->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $request->items()->create([
            'boq_item_id' => $item->id,
            'description' => 'Cement',
            'unit' => 'Bag',
            'quantity' => 2,
            'estimated_rate' => 100,
            'estimated_amount' => 200,
        ]);

        return $request;
    }

    private function draftAdjustment(Project $project): StockAdjustment
    {
        $material = Material::query()->create([
            'material_code' => 'MAT-000901',
            'name' => 'Cement',
            'unit' => 'Bag',
            'minimum_stock' => 0,
            'is_active' => true,
        ]);

        return StockAdjustment::query()->create([
            'project_id' => $project->id,
            'material_id' => $material->id,
            'adjustment_number' => 'ADJ-2026-901',
            'adjustment_date' => '2026-10-12',
            'adjustment_type' => StockAdjustment::INCREASE,
            'quantity' => 5,
            'reason' => 'Count',
            'status' => StockAdjustment::DRAFT,
        ]);
    }
}
