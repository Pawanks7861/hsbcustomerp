<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_ip_throttle_returns_429_and_ignores_forwarded_for_headers(): void
    {
        config([
            'security.throttle.login_ip' => 3,
            'security.login.max_attempts' => 50,
            'security.login.ip_max_attempts' => 50,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->from('/login')->post('/login', [
                'email' => "user{$i}@example.test",
                'password' => 'wrong-password',
            ]);
        }

        $this->from('/login')
            ->withHeaders(['X-Forwarded-For' => '198.51.100.20'])
            ->post('/login', [
                'email' => 'other@example.test',
                'password' => 'wrong-password',
            ])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_login_throttle_recovers_after_the_window(): void
    {
        config([
            'security.throttle.login_ip' => 1,
            'security.login.max_attempts' => 50,
            'security.login.ip_max_attempts' => 50,
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'burst@example.test',
            'password' => 'wrong-password',
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'burst@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->travel(2)->minutes();

        $this->from('/login')->post('/login', [
            'email' => 'burst@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(302)->assertSessionHasErrors('email');
    }

    public function test_password_reset_requests_are_throttled_without_revealing_accounts(): void
    {
        Notification::fake();
        config([
            'security.password_reset.max_attempts' => 2,
            'security.password_reset.ip_max_attempts' => 20,
            'security.password_reset.decay_minutes' => 15,
        ]);

        $user = User::factory()->create();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', trans('security.password_reset_sent'));
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'missing@example.test'])
            ->assertSessionHas('status', trans('security.password_reset_sent'));
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', trans('security.password_reset_sent'));

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_authenticated_and_expensive_routes_return_429(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        config(['security.throttle.authenticated' => 2]);
        $this->get('/')->assertOk();
        $this->get('/projects')->assertOk();
        $this->get('/vendors')->assertStatus(429)->assertHeader('Retry-After');

        config(['security.throttle.authenticated' => 1000, 'security.throttle.exports' => 1]);
        $this->get(route('reports.export.excel', 'project-summary'))->assertOk();
        $this->get(route('reports.export.excel', 'project-summary'))->assertStatus(429);

        config(['security.throttle.exports' => 60, 'security.throttle.imports' => 2]);
        $file = UploadedFile::fake()->create('vendors.csv', 10, 'text/csv');
        $this->post(route('imports.preview', 'vendors'), [
            'file' => $file,
            'duplicate_mode' => 'create_only',
        ]);
        $this->post(route('imports.preview', 'vendors'), [
            'file' => $file,
            'duplicate_mode' => 'create_only',
        ]);
        $this->post(route('imports.preview', 'vendors'), [
            'file' => $file,
            'duplicate_mode' => 'create_only',
        ])->assertStatus(429);
    }

    public function test_assistant_endpoint_is_throttled(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        config(['security.throttle.assistant' => 1]);

        $this->postJson(route('assistant.ask'), ['message' => 'How many projects?'])->assertOk();
        $this->postJson(route('assistant.ask'), ['message' => 'How many projects?'])
            ->assertStatus(429)
            ->assertJsonStructure(['message']);
    }
}
