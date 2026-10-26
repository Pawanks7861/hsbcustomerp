<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_route_shows_custom_404_page(): void
    {
        $home = url('/');

        $this->get('/this-page-definitely-does-not-exist-404')
            ->assertNotFound()
            ->assertSee('It seems like the page that you are looking for is no longer here.', false)
            ->assertSee('Take Me Home', false)
            ->assertSee('href="'.$home.'"', false);
    }

    public function test_forbidden_response_shows_custom_403_page(): void
    {
        $user = User::factory()->create();
        $home = url('/');

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertForbidden()
            ->assertSee('403-illustration.png', false)
            ->assertSee('We are Sorry...', false)
            ->assertSee('Go Back', false)
            ->assertSee('href="'.$home.'"', false);
    }

    public function test_inertia_forbidden_forces_full_page_error_location(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('users.index'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/error/403'));
    }

    public function test_inertia_not_found_forces_full_page_error_location(): void
    {
        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/this-page-definitely-does-not-exist-404')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/error/404'));
    }

    public function test_standalone_error_routes_render(): void
    {
        $this->get(route('errors.403'))->assertForbidden()->assertSee('Go Back', false);
        $this->get(route('errors.404'))->assertNotFound()->assertSee('Take Me Home', false);
    }
}
