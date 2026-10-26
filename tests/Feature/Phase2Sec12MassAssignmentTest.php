<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2Sec12MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ordinary_user_cannot_promote_themselves_through_profile_or_fill(): void
    {
        $user = User::factory()->withRoles('purchase_requester')->create([
            'is_admin' => false,
            'status' => User::STATUS_ACTIVE,
            'project_access' => 'all',
        ]);
        $loginAt = $user->last_login_at;

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Promoted Name',
            'email' => $user->email,
            'is_admin' => true,
            'status' => User::STATUS_INACTIVE,
            'project_access' => 'selected',
            'last_login_at' => '2020-01-01 00:00:00',
            'role_ids' => [Role::query()->where('slug', 'administrator')->value('id')],
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Promoted Name', $user->name);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('all', $user->project_access);
        $this->assertEquals($loginAt, $user->last_login_at);
        $this->assertTrue($user->hasRoleSlug('purchase_requester'));
        $this->assertFalse($user->hasRoleSlug('administrator'));
        $this->assertFalse(app(Access::class)->isSuperAdmin($user));

        $user->fill([
            'is_admin' => true,
            'status' => User::STATUS_INACTIVE,
            'project_access' => 'selected',
            'last_login_at' => '2020-01-01 00:00:00',
        ])->save();

        $user->refresh();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('all', $user->project_access);
        $this->assertEquals($loginAt, $user->last_login_at);
    }

    public function test_an_ordinary_user_cannot_change_another_users_privilege_fields(): void
    {
        $actor = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);
        $target = User::factory()->withRoles('viewer')->create([
            'is_admin' => false,
            'status' => User::STATUS_ACTIVE,
            'project_access' => 'all',
        ]);
        $project = Project::factory()->create();

        $this->actingAs($actor)->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'status' => User::STATUS_INACTIVE,
            'is_admin' => true,
            'role_ids' => [Role::query()->where('slug', 'administrator')->value('id')],
            'project_access' => 'selected',
            'project_ids' => [$project->id],
        ])->assertForbidden();

        $this->actingAs($actor)->patch(route('users.status', $target), [
            'status' => User::STATUS_INACTIVE,
        ])->assertForbidden();

        $this->actingAs($actor)->put(route('users.permissions.update', $target), [
            'overrides' => [
                ['permission' => 'users.create', 'mode' => 'allow'],
            ],
        ])->assertForbidden();

        $target->refresh();
        $this->assertFalse((bool) $target->is_admin);
        $this->assertSame(User::STATUS_ACTIVE, $target->status);
        $this->assertSame('all', $target->project_access);
        $this->assertTrue($target->hasRoleSlug('viewer'));
        $this->assertFalse($target->hasRoleSlug('administrator'));
        $this->assertSame(0, $target->permissionOverrides()->count());
        $this->assertFalse($target->allows('users.create'));
    }

    public function test_an_ordinary_user_cannot_grant_themselves_roles_or_overrides(): void
    {
        $user = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);

        $this->actingAs($user)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => User::STATUS_ACTIVE,
            'is_admin' => true,
            'role_ids' => [Role::query()->where('slug', 'administrator')->value('id')],
            'project_access' => 'all',
        ])->assertForbidden();

        $this->actingAs($user)->put(route('users.permissions.update', $user), [
            'overrides' => [
                ['permission' => 'users.create', 'mode' => 'allow'],
            ],
        ])->assertForbidden();

        $user->refresh();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertTrue($user->hasRoleSlug('purchase_requester'));
        $this->assertFalse($user->hasRoleSlug('administrator'));
        $this->assertSame(0, $user->permissionOverrides()->count());
        $this->assertFalse($user->allows('users.create'));
    }

    public function test_password_update_cannot_set_privilege_fields(): void
    {
        $user = User::factory()->withRoles('purchase_requester')->create([
            'is_admin' => false,
            'status' => User::STATUS_ACTIVE,
            'project_access' => 'all',
        ]);

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'is_admin' => true,
            'status' => User::STATUS_INACTIVE,
            'project_access' => 'selected',
        ])->assertRedirect('/profile');

        $user->refresh();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('all', $user->project_access);
        $this->assertTrue($user->hasRoleSlug('purchase_requester'));
    }

    public function test_an_administrator_can_manage_staff_access_but_cannot_set_super_admin(): void
    {
        $manager = User::factory()->withRoles('administrator')->create(['is_admin' => false]);
        $staff = User::factory()->withRoles('viewer')->create([
            'is_admin' => false,
            'status' => User::STATUS_ACTIVE,
            'project_access' => 'all',
        ]);
        $requester = Role::query()->where('slug', 'purchase_requester')->firstOrFail();
        $project = Project::factory()->create();

        $this->actingAs($manager)->put(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'status' => User::STATUS_ACTIVE,
            'is_admin' => true,
            'role_ids' => [$requester->id],
            'project_access' => 'selected',
            'project_ids' => [$project->id],
        ])->assertForbidden();

        $staff->refresh();
        $this->assertFalse((bool) $staff->is_admin);
        $this->assertTrue($staff->hasRoleSlug('viewer'));
        $this->assertSame('all', $staff->project_access);

        $this->actingAs($manager)->put(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'status' => User::STATUS_INACTIVE,
            'is_admin' => false,
            'role_ids' => [$requester->id],
            'project_access' => 'selected',
            'project_ids' => [$project->id],
        ])->assertRedirect(route('users.index'));

        $staff->refresh();
        $this->assertFalse((bool) $staff->is_admin);
        $this->assertSame(User::STATUS_INACTIVE, $staff->status);
        $this->assertSame('selected', $staff->project_access);
        $this->assertTrue($staff->hasRoleSlug('purchase_requester'));
        $this->assertFalse($staff->hasRoleSlug('viewer'));
        $this->assertEquals([$project->id], $staff->projects()->pluck('projects.id')->all());
    }

    public function test_a_super_admin_can_create_and_update_privileged_users(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $viewer = Role::query()->where('slug', 'viewer')->firstOrFail();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Second Admin',
            'email' => 'second-admin@example.com',
            'status' => User::STATUS_ACTIVE,
            'is_admin' => true,
            'role_ids' => [],
            'project_access' => 'all',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect(route('users.index'));

        $created = User::query()->where('email', 'second-admin@example.com')->firstOrFail();
        $this->assertTrue((bool) $created->is_admin);
        $this->assertSame(User::STATUS_ACTIVE, $created->status);
        $this->assertSame('all', $created->project_access);
        $this->assertTrue(app(Access::class)->isSuperAdmin($created));

        $staff = User::factory()->withRoles('viewer')->create(['is_admin' => false]);

        $this->actingAs($admin)->put(route('users.update', $staff), [
            'name' => 'Scoped Staff',
            'email' => $staff->email,
            'status' => User::STATUS_ACTIVE,
            'is_admin' => false,
            'role_ids' => [$viewer->id],
            'project_access' => 'selected',
            'project_ids' => [$project->id],
        ])->assertRedirect(route('users.index'));

        $staff->refresh();
        $this->assertSame('Scoped Staff', $staff->name);
        $this->assertFalse((bool) $staff->is_admin);
        $this->assertSame('selected', $staff->project_access);
        $this->assertTrue($staff->hasRoleSlug('viewer'));
        $this->assertEquals([$project->id], $staff->projects()->pluck('projects.id')->all());
    }

    public function test_updating_ordinary_profile_fields_does_not_change_roles(): void
    {
        $user = User::factory()->withRoles('purchase_requester')->create([
            'is_admin' => false,
            'project_access' => 'all',
        ]);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Renamed Requester',
            'email' => $user->email,
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Renamed Requester', $user->name);
        $this->assertTrue($user->hasRoleSlug('purchase_requester'));
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame('all', $user->project_access);
        $this->assertTrue($user->allows('purchase_requests.create'));
        $this->assertFalse($user->allows('users.create'));
    }

    public function test_roleless_users_remain_denied_after_privilege_hardening(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->assertFalse($user->roles()->exists());
        $this->assertFalse(app(Access::class)->allows($user, 'dashboard.view'));
        $this->assertSame([], app(Access::class)->grantedNames($user));

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => true,
            'status' => User::STATUS_ACTIVE,
            'project_access' => 'all',
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertFalse(app(Access::class)->isSuperAdmin($user));
        $this->assertFalse($user->allows('dashboard.view'));
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }
}
