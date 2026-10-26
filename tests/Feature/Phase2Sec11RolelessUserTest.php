<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Access;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2Sec11RolelessUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_roleless_non_admin_does_not_receive_legacy_permissions(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $access = app(Access::class);

        $this->assertFalse($user->roles()->exists());
        $this->assertFalse($access->isSuperAdmin($user));
        $this->assertFalse($access->allows($user, 'purchase_requests.create'));
        $this->assertFalse($access->allows($user, 'purchase_orders.create'));
        $this->assertFalse($access->allows($user, 'dashboard.view'));
        $this->assertSame([], $access->grantedNames($user));
        $this->assertContains('purchase_requests.create', Permissions::legacy());
    }

    public function test_a_roleless_user_cannot_open_protected_routes(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $project = Project::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('projects.purchase-requests.create', $project))->assertForbidden();
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_a_role_based_user_keeps_role_grants_and_a_super_admin_keeps_full_access(): void
    {
        $requester = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);
        $admin = User::factory()->admin()->create();
        $access = app(Access::class);
        $project = Project::factory()->create();

        $this->assertTrue($access->allows($requester, 'purchase_requests.create'));
        $this->assertFalse($access->allows($requester, 'purchase_orders.create'));
        $this->assertTrue($access->allows($admin, 'users.create'));
        $this->assertTrue($access->allows($admin, 'purchase_orders.approve'));

        $this->actingAs($requester)->get(route('projects.purchase-requests.create', $project))->assertOk();
        $this->actingAs($requester)->get(route('users.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_removing_roles_does_not_restore_legacy_grants_on_a_fresh_user(): void
    {
        $user = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);
        $access = app(Access::class);

        $this->assertTrue($access->allows($user, 'purchase_requests.create'));
        $access->grantedNames($user);

        $user->roles()->sync([]);
        $user->unsetRelation('roles');
        $user->unsetRelation('permissionOverrides');

        $this->assertFalse($access->allows($user, 'purchase_requests.create'));
        $this->assertSame([], $access->grantedNames($user));
        $this->assertFalse($user->fresh()->allows('purchase_requests.create'));
        $this->assertFalse($user->fresh()->allows('dashboard.view'));
    }

    public function test_editing_a_staff_user_cannot_clear_all_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => User::STATUS_ACTIVE,
            'is_admin' => false,
            'role_ids' => [],
            'project_access' => 'all',
        ])->assertSessionHasErrors('role_ids');

        $this->assertTrue($user->fresh()->hasRoleSlug('purchase_requester'));
        $this->assertTrue($user->fresh()->allows('purchase_requests.create'));
    }

    public function test_changing_roles_updates_effective_permissions_for_the_next_request(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);
        $viewer = Role::query()->where('slug', 'viewer')->firstOrFail();
        $project = Project::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.permissions', function ($permissions) {
                $names = collect($permissions)->all();

                return in_array('purchase_requests.create', $names, true)
                    && in_array('dashboard.view', $names, true);
            }));

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => User::STATUS_ACTIVE,
            'is_admin' => false,
            'role_ids' => [$viewer->id],
            'project_access' => 'all',
        ])->assertRedirect('/users');

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.permissions', function ($permissions) {
                $names = collect($permissions)->all();

                return ! in_array('purchase_requests.create', $names, true)
                    && in_array('projects.view', $names, true);
            }));

        $this->actingAs($user->fresh())
            ->get(route('projects.purchase-requests.create', $project))
            ->assertForbidden();
    }

    public function test_creating_a_staff_user_requires_a_role_and_super_admin_does_not(): void
    {
        $admin = User::factory()->admin()->create();
        $requester = Role::query()->where('slug', 'purchase_requester')->firstOrFail();

        $this->actingAs($admin)->post('/users', [
            'name' => 'No Role Staff',
            'email' => 'norole@example.com',
            'status' => 'active',
            'is_admin' => false,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertSessionHasErrors('role_ids');
        $this->assertNull(User::query()->where('email', 'norole@example.com')->first());

        $this->actingAs($admin)->post('/users', [
            'name' => 'Requester Staff',
            'email' => 'requester@example.com',
            'status' => 'active',
            'is_admin' => false,
            'role_ids' => [$requester->id],
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect('/users');

        $created = User::query()->where('email', 'requester@example.com')->firstOrFail();
        $this->assertTrue($created->hasRoleSlug('purchase_requester'));
        $this->assertTrue($created->allows('purchase_requests.create'));
        $this->assertFalse($created->allows('users.create'));

        $this->actingAs($admin)->post('/users', [
            'name' => 'Second Admin',
            'email' => 'second-admin@example.com',
            'status' => 'active',
            'is_admin' => true,
            'role_ids' => [],
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect('/users');

        $second = User::query()->where('email', 'second-admin@example.com')->firstOrFail();
        $this->assertTrue($second->is_admin);
        $this->assertFalse($second->roles()->exists());
        $this->assertTrue(app(Access::class)->isSuperAdmin($second));
        $this->assertTrue($second->allows('users.create'));
    }

    public function test_an_explicit_allow_override_is_not_a_legacy_grant(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission' => 'projects.view',
            'effect' => UserPermissionOverride::ALLOW,
        ]);

        $access = app(Access::class);
        $this->assertTrue($access->allows($user->fresh(), 'projects.view'));
        $this->assertFalse($access->allows($user->fresh(), 'purchase_requests.create'));
        $this->assertSame(['projects.view'], $access->grantedNames($user->fresh()));
    }
}
