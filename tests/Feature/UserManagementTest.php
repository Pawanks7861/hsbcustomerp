<?php

namespace Tests\Feature;

use App\Models\User;
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

        $this->actingAs($admin)->post('/users', [
            'name' => 'Site Engineer',
            'email' => 'engineer@example.com',
            'phone' => '9999900000',
            'designation' => 'Engineer',
            'status' => 'active',
            'is_admin' => false,
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
}
