<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_logins_lock_temporarily_without_suspending_the_account(): void
    {
        $user = User::factory()->create(['email' => 'staff@hsb.local', 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $locked = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $locked->assertStatus(429);
        $locked->assertHeader('Retry-After');
        $locked->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(User::STATUS_ACTIVE, $user->refresh()->status);
        $this->assertTrue(ActivityLog::query()->where('event', ActivityLog::EVENT_LOGIN_LOCKOUT)->exists());

        $this->travel(16)->minutes();

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_resets_the_failure_counter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        for ($i = 0; $i < 4; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(302);
            $this->assertGuest();
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_lockout_and_failure_messages_do_not_reveal_whether_an_account_exists(): void
    {
        $user = User::factory()->create();

        $unknown = $this->from('/login')->post('/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ]);
        $known = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(
            $unknown->getSession()->get('errors')->first('email'),
            $known->getSession()->get('errors')->first('email'),
        );
        $this->assertSame(trans('auth.failed'), $known->getSession()->get('errors')->first('email'));

        for ($i = 0; $i < 4; $i++) {
            $this->from('/login')->post('/login', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
            ]);
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $unknownLocked = $this->from('/login')->post('/login', [
            'email' => 'missing@example.test',
            'password' => 'password',
        ]);
        $knownLocked = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $unknownLocked->assertStatus(429);
        $knownLocked->assertStatus(429);
        $this->assertSame(
            $unknownLocked->getSession()->get('errors')->first('email'),
            $knownLocked->getSession()->get('errors')->first('email'),
        );
        $this->assertStringNotContainsString('missing@example.test', $unknownLocked->getSession()->get('errors')->first('email'));
        $this->assertStringNotContainsString($user->email, $knownLocked->getSession()->get('errors')->first('email'));
    }

    public function test_password_reset_still_works_while_login_is_locked(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(429);

        $this->from('/forgot-password')->post('/forgot-password', [
            'email' => $user->email,
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame(User::STATUS_ACTIVE, $user->refresh()->status);
    }

    public function test_validation_errors_do_not_count_toward_lockout(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => 'not-an-email',
                'password' => '',
            ])->assertSessionHasErrors();
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_accounts_are_not_treated_as_failed_password_attempts(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email')->assertStatus(302);
        $this->assertFalse(
            ActivityLog::query()->where('event', ActivityLog::EVENT_LOGIN_LOCKOUT)->exists()
        );
    }
}
