<?php

namespace Tests\Unit;

use App\Services\Security\LoginLockout;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginLockoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_five_failures_lock_the_identifier_and_concurrent_calls_share_the_counter(): void
    {
        config([
            'security.login.max_attempts' => 5,
            'security.login.decay_seconds' => 900,
            'security.login.lockout_seconds' => 900,
            'security.login.ip_max_attempts' => 50,
        ]);

        $lockout = app(LoginLockout::class);
        $email = 'lockout@example.test';
        $ip = '203.0.113.10';

        for ($i = 0; $i < 5; $i++) {
            $lockout->serializeAccount($email, function () use ($lockout, $email, $ip): void {
                $lockout->recordFailure($email, $ip);
            });
        }

        $request = Request::create('/login', 'POST', ['email' => $email, 'password' => 'x']);
        $request->server->set('REMOTE_ADDR', $ip);

        try {
            $lockout->assertCanAttempt($request);
            $this->fail('Expected a lockout exception.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertGreaterThan(0, (int) $exception->getHeaders()['Retry-After']);
            $this->assertStringContainsString('Too many login attempts', $exception->getMessage());
        }
    }

    public function test_success_clears_the_account_counter(): void
    {
        $lockout = app(LoginLockout::class);
        $email = 'ok@example.test';
        $ip = '203.0.113.11';

        for ($i = 0; $i < 4; $i++) {
            $lockout->recordFailure($email, $ip);
        }

        $lockout->recordSuccess($email, $ip);

        $request = Request::create('/login', 'POST', ['email' => $email]);
        $request->server->set('REMOTE_ADDR', $ip);
        $lockout->assertCanAttempt($request);

        $this->assertSame(0, $lockout->remainingSeconds($email, $ip));
    }
}
