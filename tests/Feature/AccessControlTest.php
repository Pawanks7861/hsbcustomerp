<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
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
        $user = User::factory()->create([
            'role_id' => $role->id,
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
            'role_id' => Role::query()->where('slug', 'administrator')->value('id'),
        ])->assertForbidden();

        $manager = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'administrator')->value('id'),
        ]);
        $this->actingAs($manager)->patch(route('users.status', $admin), ['status' => 'inactive'])->assertRedirect();
        $this->assertSame('active', $admin->fresh()->status);
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
}
