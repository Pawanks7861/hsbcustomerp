<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2Sec13ErrorRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_users_can_open_branded_error_pages_with_the_correct_status(): void
    {
        $this->get(route('errors.403'))
            ->assertForbidden()
            ->assertSee('We are Sorry...', false)
            ->assertSee('403-illustration.png', false)
            ->assertSee('Go Back', false)
            ->assertDontSee('Take Me Home', false);

        $this->get(route('errors.404'))
            ->assertNotFound()
            ->assertSee('It seems like the page that you are looking for is no longer here.', false)
            ->assertSee('Take Me Home', false)
            ->assertDontSee('We are Sorry...', false);
    }

    public function test_error_pages_do_not_expose_exception_messages_stack_traces_or_paths(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        foreach ([
            $this->get(route('errors.403')),
            $this->get(route('errors.404')),
            $this->actingAs($user)->get(route('users.index')),
            $this->get('/this-page-definitely-does-not-exist-404'),
        ] as $response) {
            $response->assertDontSee('stack trace', false);
            $response->assertDontSee('Stack trace', false);
            $response->assertDontSee('vendor/laravel', false);
            $response->assertDontSee('Illuminate\\', false);
            $response->assertDontSee(base_path(), false);
            $response->assertDontSee('SQLSTATE', false);
            $response->assertDontSee('#0 ', false);
        }
    }

    public function test_error_pages_do_not_reveal_protected_project_user_or_financial_data(): void
    {
        $project = Project::factory()->create(['project_name' => 'SecretChamberWorksXYZ']);
        $user = User::factory()->create([
            'name' => 'HiddenStaffXYZ',
            'email' => 'hidden-staff-xyz@example.com',
            'is_admin' => false,
        ]);

        foreach ([
            $this->get(route('errors.403')),
            $this->get(route('errors.404')),
            $this->actingAs($user)->get(route('users.index')),
            $this->actingAs($user)->get(route('projects.show', $project)),
        ] as $response) {
            $response->assertDontSee('SecretChamberWorksXYZ', false);
            $response->assertDontSee('HiddenStaffXYZ', false);
            $response->assertDontSee('hidden-staff-xyz@example.com', false);
        }

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('projects.show', $project))->assertForbidden();
    }

    public function test_query_parameters_cannot_inject_content_into_error_pages(): void
    {
        $token = 'sec13-probe-'.bin2hex(random_bytes(6));
        $query = [
            'message' => '<script>alert('.$token.')</script>',
            'exception' => $token,
            'file' => 'C:\\wamp64\\www\\hsbcustomerp\\'.$token,
            'sql' => 'select * from users where email = '.$token,
        ];

        $this->get(route('errors.403', $query))
            ->assertForbidden()
            ->assertDontSee($token, false)
            ->assertDontSee('<script>alert(', false);

        $this->get(route('errors.404', $query))
            ->assertNotFound()
            ->assertDontSee($token, false)
            ->assertDontSee('<script>alert(', false);
    }

    public function test_visiting_the_public_error_route_does_not_bypass_protected_resources(): void
    {
        $this->get(route('errors.403'))->assertForbidden();
        $this->get(route('users.index'))->assertRedirect('/login');
        $this->get(route('dashboard'))->assertRedirect('/login');

        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('errors.403'))->assertForbidden();
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_authenticated_users_keep_branded_error_pages_and_inertia_full_page_redirects(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->withRoles('purchase_requester')->create(['is_admin' => false]);

        $this->actingAs($staff)
            ->get(route('users.index'))
            ->assertForbidden()
            ->assertSee('We are Sorry...', false);

        $this->actingAs($admin)
            ->get('/this-page-definitely-does-not-exist-404')
            ->assertNotFound()
            ->assertSee('Take Me Home', false);

        $this->actingAs($staff)
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/users?secret=payroll-leak-xyz')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/error/403'));

        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/missing-route?secret=payroll-leak-xyz')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/error/404'));
    }
}
