<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleMenus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_users_and_normal_users_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/users')->assertForbidden();
        $this->actingAs($user)->get('/users/create')->assertForbidden();

        $this->actingAs($admin)->get('/users')->assertOk();

        $requester = Role::query()->where('slug', 'purchase_requester')->firstOrFail();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Site Engineer',
            'email' => 'engineer@example.com',
            'phone' => '9999900000',
            'designation' => 'Engineer',
            'status' => 'active',
            'is_admin' => false,
            'role_ids' => [$requester->id],
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect('/users');

        $created = User::query()->where('email', 'engineer@example.com')->firstOrFail();
        $this->assertFalse($created->is_admin);
        $this->assertSame('active', $created->status);

        $this->post('/logout');
        $this->post('/login', [
            'email' => 'engineer@example.com',
            'password' => 'secret123',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($created);

        $this->actingAs($admin)->put("/users/{$created->id}", [
            'name' => 'Site Engineer',
            'email' => 'engineer@example.com',
            'phone' => '9999900000',
            'designation' => 'Engineer',
            'status' => 'active',
            'is_admin' => false,
            'role_ids' => [$requester->id],
            'password' => 'newsecret1',
            'password_confirmation' => 'newsecret1',
        ])->assertRedirect('/users');

        $this->post('/logout');
        $this->post('/login', [
            'email' => 'engineer@example.com',
            'password' => 'newsecret1',
        ])->assertRedirect('/');

        $this->actingAs($admin)->patch("/users/{$created->id}/status", [
            'status' => 'inactive',
        ])->assertRedirect();

        $this->post('/logout');
        $this->post('/login', [
            'email' => 'engineer@example.com',
            'password' => 'newsecret1',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)->patch("/users/{$created->id}/status", [
            'status' => 'active',
        ]);

        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertRedirect();
        $this->assertNotNull($admin->fresh());

        $this->actingAs($admin)->delete("/users/{$created->id}")->assertRedirect('/users');
        $this->assertNull($created->fresh());
    }

    public function test_create_user_form_skips_admin_roles_and_shows_staff_menus(): void
    {
        $admin = User::factory()->admin()->create();
        $requester = Role::query()->where('slug', 'purchase_requester')->firstOrFail();

        $this->actingAs($admin)
            ->get('/users/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Users/Form')
                ->where('can_assign_super_admin', true)
                ->where('roles', function ($roles) use ($requester) {
                    $slugs = collect($roles)->pluck('slug');

                    return $slugs->contains($requester->slug)
                        && $slugs->intersect(RoleMenus::privilegedSlugs())->isEmpty()
                        && collect($roles)->firstWhere('slug', $requester->slug)['menus'] !== [];
                }));
    }

    public function test_staff_users_get_a_menu_tutorial_and_admins_skip_it(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->withRoles('administrator')->create();
        $requester = User::factory()->withRoles('purchase_requester')->create();

        $this->actingAs($admin)->get('/')->assertInertia(fn ($page) => $page->where('menu_tutorial', null));
        $this->actingAs($manager)->get('/')->assertInertia(fn ($page) => $page->where('menu_tutorial', null));

        $this->actingAs($requester)
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('menu_tutorial', function ($tutorial) {
                $tutorial = collect($tutorial);

                return $tutorial->isNotEmpty()
                    && collect($tutorial->get('menus'))->isNotEmpty()
                    && collect($tutorial->get('roles'))->contains('Purchase Requester');
            }));
    }

    public function test_completed_menu_tutorial_is_not_shown_again(): void
    {
        $requester = User::factory()->withRoles('purchase_requester')->create();

        $this->actingAs($requester)
            ->from('/')
            ->post(route('menu-tutorial.complete'))
            ->assertRedirect('/');

        $this->assertNotNull($requester->fresh()->menu_tutorial_completed_at);

        $this->actingAs($requester)
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('menu_tutorial', null));

        $this->actingAs($requester)
            ->from('/')
            ->post(route('menu-tutorial.complete'))
            ->assertRedirect('/');

        $this->assertNotNull($requester->fresh()->menu_tutorial_completed_at);
    }
}
