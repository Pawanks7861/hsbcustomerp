<?php

namespace App\Services\Security;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecurityEventLogger
{
    public function __construct(private ActivityLogger $logger) {}

    public function lockout(?int $userId, string $scope, string $identifierHash, string $ip, int $retryAfter): void
    {
        $onceKey = 'security-log:lockout:'.$scope.':'.$identifierHash;
        if (! Cache::add($onceKey, 1, max(60, $retryAfter))) {
            return;
        }

        $this->safe(function () use ($userId, $scope, $identifierHash, $ip, $retryAfter): void {
            $user = $userId ? User::query()->find($userId) : null;
            $this->logger->auth(ActivityLog::EVENT_LOGIN_LOCKOUT, $user, [
                'scope' => $scope,
                'identifier_hash' => $identifierHash,
                'ip_address' => $ip,
                'retry_after' => $retryAfter,
            ]);
        });
    }

    public function rateLimited(Request $request, int $retryAfter): void
    {
        $actor = $request->user()?->getAuthIdentifier() ?: $request->ip();
        $onceKey = 'security-log:rl:'.sha1(($request->route()?->getName() ?: $request->path()).'|'.$actor);
        if (! Cache::add($onceKey, 1, max(60, $retryAfter))) {
            return;
        }

        $this->safe(function () use ($request, $retryAfter): void {
            $this->logger->auth(ActivityLog::EVENT_RATE_LIMITED, $request->user(), [
                'route' => $request->route()?->getName(),
                'retry_after' => $retryAfter,
            ]);
        });
    }

    public function passwordResetRequest(string $emailHash): void
    {
        $onceKey = 'security-log:pwr:'.$emailHash;
        if (! Cache::add($onceKey, 1, 60)) {
            return;
        }

        $this->safe(function () use ($emailHash): void {
            $this->logger->auth(ActivityLog::EVENT_PASSWORD_RESET_REQUEST, null, [
                'identifier_hash' => $emailHash,
            ]);
        });
    }

    private function safe(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Security event could not be audited', ['error' => $exception->getMessage()]);
        }
    }
}
