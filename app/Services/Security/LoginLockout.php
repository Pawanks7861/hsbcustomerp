<?php

namespace App\Services\Security;

use App\Models\User;
use App\Support\SafeInput;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class LoginLockout
{
    public function __construct(private SecurityEventLogger $events) {}

    public function normalize(string $email): string
    {
        return SafeInput::normalizeEmail($email);
    }

    public function identifierHash(string $email): string
    {
        return hash('sha256', $this->normalize($email));
    }

    public function assertCanAttempt(Request $request): void
    {
        $email = $this->normalize((string) $request->input('email', ''));
        $ip = (string) $request->ip();

        try {
            $this->throwIfLocked($email, $ip);
        } catch (ThrottleRequestsException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw $this->exception((int) config('security.login.lockout_seconds'));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function serializeAccount(string $email, callable $callback): mixed
    {
        $hash = $this->identifierHash($email);
        $lock = Cache::lock('login-auth:'.$hash, 10);

        try {
            $lock->block(5);

            return $callback();
        } catch (LockTimeoutException $exception) {
            report($exception);
            throw $this->exception((int) config('security.login.lockout_seconds'));
        } finally {
            $lock->release();
        }
    }

    public function recordFailure(string $email, string $ip): void
    {
        $normalized = $this->normalize($email);
        $decay = (int) config('security.login.decay_seconds');
        $lockout = (int) config('security.login.lockout_seconds');
        $max = (int) config('security.login.max_attempts');
        $ipMax = (int) config('security.login.ip_max_attempts');

        RateLimiter::hit($this->accountKey($normalized), $decay);
        RateLimiter::hit($this->comboKey($normalized, $ip), $decay);
        RateLimiter::hit($this->ipKey($ip), $decay);

        if (RateLimiter::attempts($this->accountKey($normalized)) >= $max) {
            Cache::put($this->accountLockKey($normalized), time() + $lockout, $lockout);
            $this->events->lockout(
                $this->userIdFor($normalized),
                'account',
                $this->identifierHash($normalized),
                $ip,
                $lockout,
            );
        }

        if (RateLimiter::attempts($this->ipKey($ip)) >= $ipMax) {
            Cache::put($this->ipLockKey($ip), time() + $lockout, $lockout);
            $this->events->lockout(
                $this->userIdFor($normalized),
                'ip',
                hash('sha256', $ip),
                $ip,
                $lockout,
            );
        }
    }

    public function recordSuccess(string $email, string $ip): void
    {
        $normalized = $this->normalize($email);

        RateLimiter::clear($this->accountKey($normalized));
        RateLimiter::clear($this->comboKey($normalized, $ip));
        Cache::forget($this->accountLockKey($normalized));
    }

    public function remainingSeconds(string $email, string $ip): int
    {
        $normalized = $this->normalize($email);
        $candidates = [
            $this->lockRemaining($this->accountLockKey($normalized)),
            $this->lockRemaining($this->ipLockKey($ip)),
            RateLimiter::tooManyAttempts($this->accountKey($normalized), (int) config('security.login.max_attempts'))
                ? RateLimiter::availableIn($this->accountKey($normalized))
                : 0,
            RateLimiter::tooManyAttempts($this->comboKey($normalized, $ip), (int) config('security.login.max_attempts'))
                ? RateLimiter::availableIn($this->comboKey($normalized, $ip))
                : 0,
            RateLimiter::tooManyAttempts($this->ipKey($ip), (int) config('security.login.ip_max_attempts'))
                ? RateLimiter::availableIn($this->ipKey($ip))
                : 0,
        ];

        return max($candidates);
    }

    private function throwIfLocked(string $email, string $ip): void
    {
        $remaining = $this->remainingSeconds($email, $ip);
        if ($remaining > 0) {
            throw $this->exception($remaining);
        }
    }

    private function exception(int $seconds): ThrottleRequestsException
    {
        $seconds = max(1, $seconds);
        $minutes = (int) ceil($seconds / 60);

        return new ThrottleRequestsException(
            trans('security.lockout', ['seconds' => $seconds, 'minutes' => $minutes]),
            null,
            ['Retry-After' => $seconds],
        );
    }

    private function lockRemaining(string $key): int
    {
        $until = Cache::get($key);
        if (! is_numeric($until)) {
            return Cache::has($key) ? (int) config('security.login.lockout_seconds') : 0;
        }

        return max(0, (int) $until - time());
    }

    private function userIdFor(string $normalizedEmail): ?int
    {
        try {
            return User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->value('id');
        } catch (Throwable) {
            return null;
        }
    }

    private function accountKey(string $normalized): string
    {
        return 'login:account:'.$this->identifierHash($normalized);
    }

    private function comboKey(string $normalized, string $ip): string
    {
        return 'login:combo:'.$this->identifierHash($normalized).'|'.$ip;
    }

    private function ipKey(string $ip): string
    {
        return 'login:ip:'.$ip;
    }

    private function accountLockKey(string $normalized): string
    {
        return 'login:lock:account:'.$this->identifierHash($normalized);
    }

    private function ipLockKey(string $ip): string
    {
        return 'login:lock:ip:'.$ip;
    }
}
