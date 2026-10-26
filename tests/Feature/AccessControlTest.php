<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_permissions_override_the_role_and_projects_are_restricted(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->where('slug', 'purchase_requester')->firstOrFail();
        $allowed = Project::factory()->create();
        $blocked = Project::factory()->create();
        $user = User::factory()->withRoles($role->slug)->create([
            'project_access' => 'selected',
        ]);
        $user->projects()->sync([$allowed->id]);

        $this->actingAs($user)->get('/users')->assertForbidden();
        $this->actingAs($user)->get(route('projects.show', $blocked))->assertNotFound();
        $this->actingAs($user)->get(route('projects.show', $allowed))->assertOk();
        $this->actingAs($user)->get('/roles')->assertForbidden();

        $this->actingAs($admin)->put(route('users.permissions.update', $user), [
            'overrides' => [
                ['permission' => 'users.view', 'mode' => 'allow'],
            ],
        ])->assertRedirect();
        $this->actingAs($user->fresh())->get('/users')->assertOk();

        UserPermissionOverride::query()->where('user_id', $user->id)->update(['effect' => 'deny']);
        $this->actingAs($user->fresh())->get('/users')->assertForbidden();

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => 'active',
            'is_admin' => true,
            'role_ids' => [Role::query()->where('slug', 'administrator')->value('id')],
        ])->assertForbidden();

        $manager = User::factory()->withRoles('administrator')->create();
        $this->actingAs($manager)->patch(route('users.status', $admin), ['status' => 'inactive'])->assertRedirect();
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_a_user_can_hold_multiple_roles_and_gains_their_combined_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $requester = Role::query()->where('slug', 'purchase_requester')->firstOrFail();
        $storekeeper = Role::query()->where('slug', 'storekeeper')->firstOrFail();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Mixed Roles',
            'email' => 'mixed@hsb.local',
            'status' => 'active',
            'is_admin' => false,
            'role_ids' => [$requester->id, $storekeeper->id],
            'project_access' => 'all',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('users.index'));

        $user = User::query()->where('email', 'mixed@hsb.local')->firstOrFail();
        $this->assertEqualsCanonicalizing([$requester->id, $storekeeper->id], $user->roles()->pluck('roles.id')->all());
        $this->assertTrue($user->allows('purchase_requests.create'));
        $this->assertTrue($user->allows('inventory.view'));

        $this->actingAs($admin)
            ->get(route('users.edit', $user))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('user.role_ids', fn ($ids) => collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all()
                    === collect([$requester->id, $storekeeper->id])->sort()->values()->all()));

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.data', function ($users) use ($requester, $storekeeper) {
                $row = collect($users)->firstWhere('email', 'mixed@hsb.local');

                return $row
                    && str_contains($row['role'], $requester->name)
                    && str_contains($row['role'], $storekeeper->name);
            }));
    }

    public function test_login_still_works_for_an_active_user(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_deny_override_blocks_a_permission_granted_by_role(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->where('slug', 'storekeeper')->firstOrFail();
        $user = User::factory()->withRoles($role->slug)->create(['project_access' => 'all']);

        $this->assertTrue($user->allows('inventory.view'));

        $this->actingAs($admin)->put(route('users.permissions.update', $user), [
            'overrides' => [
                ['permission' => 'inventory.view', 'mode' => 'deny'],
            ],
        ])->assertRedirect();

        $this->assertFalse($user->fresh()->allows('inventory.view'));
    }

    public function test_allow_override_grants_a_permission_missing_from_role(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->where('slug', 'viewer')->firstOrFail();
        $user = User::factory()->withRoles($role->slug)->create(['project_access' => 'all']);

        $this->assertFalse($user->allows('projects.create'));

        $this->actingAs($admin)->put(route('users.permissions.update', $user), [
            'overrides' => [
                ['permission' => 'projects.create', 'mode' => 'allow'],
            ],
        ])->assertRedirect();

        $this->actingAs($user->fresh())->get('/projects/create')->assertOk();
    }

    public function test_resetting_an_override_to_inherit_restores_role_default(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->where('slug', 'storekeeper')->firstOrFail();
        $user = User::factory()->withRoles($role->slug)->create(['project_access' => 'all']);

        $this->actingAs($admin)->put(route('users.permissions.update', $user), [
            'overrides' => [
                ['permission' => 'inventory.view', 'mode' => 'deny'],
            ],
        ])->assertRedirect();
        $this->assertFalse($user->fresh()->allows('inventory.view'));

        $this->actingAs($admin)->put(route('users.permissions.update', $user), [
            'overrides' => [
                ['permission' => 'inventory.view', 'mode' => 'inherit'],
            ],
        ])->assertRedirect();
        $this->assertTrue($user->fresh()->allows('inventory.view'));
    }

    public function test_vendor_ledger_requires_vendors_ledger_permission(): void
    {
        $viewer = User::factory()->withRoles('viewer')->create(['project_access' => 'all']);
        $vendor = Vendor::factory()->create();

        $this->actingAs($viewer)->get(route('vendors.report'))->assertOk();
        $this->actingAs($viewer)->get(route('vendors.ledger', $vendor))->assertForbidden();
    }
}
