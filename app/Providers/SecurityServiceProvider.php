<?php

namespace App\Providers;

use App\Http\Responses\TooManyAttempts;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $tooMany = fn (Request $request, array $headers) => app(TooManyAttempts::class)->toResponse($request, $headers);

        RateLimiter::for('login', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.login_ip'))
                ->by($request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('password-reset', function (Request $request) use ($tooMany) {
            $email = Str::lower(trim((string) $request->input('email')));
            $emailKey = $email !== '' ? hash('sha256', $email) : 'none';

            return [
                Limit::perMinutes(
                    (int) config('security.password_reset.decay_minutes'),
                    (int) config('security.password_reset.max_attempts'),
                )->by($emailKey)->response($tooMany),
                Limit::perMinutes(
                    (int) config('security.password_reset.decay_minutes'),
                    (int) config('security.password_reset.ip_max_attempts'),
                )->by($request->ip())->response($tooMany),
            ];
        });

        RateLimiter::for('password-reset-submit', function (Request $request) use ($tooMany) {
            return Limit::perMinutes(
                (int) config('security.password_reset.submit_decay_minutes'),
                (int) config('security.password_reset.submit_max_attempts'),
            )->by($request->ip())->response($tooMany);
        });

        RateLimiter::for('password-confirm', function (Request $request) use ($tooMany) {
            return Limit::perMinutes(
                (int) config('security.password_confirm.decay_minutes'),
                (int) config('security.password_confirm.max_attempts'),
            )->by($request->user()?->getAuthIdentifier() ?: $request->ip())->response($tooMany);
        });

        RateLimiter::for('password-update', function (Request $request) use ($tooMany) {
            return Limit::perMinutes(
                (int) config('security.password_update.decay_minutes'),
                (int) config('security.password_update.max_attempts'),
            )->by($request->user()?->getAuthIdentifier() ?: $request->ip())->response($tooMany);
        });

        RateLimiter::for('authenticated', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.authenticated'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('writes', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.writes'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('sensitive', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.sensitive'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('imports', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.imports'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('exports', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.exports'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('pdfs', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.pdfs'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('search', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.search'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });

        RateLimiter::for('assistant', function (Request $request) use ($tooMany) {
            return Limit::perMinute((int) config('security.throttle.assistant'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip())
                ->response($tooMany);
        });
    }
}
