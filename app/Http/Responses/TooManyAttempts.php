<?php

namespace App\Http\Responses;

use App\Services\Security\SecurityEventLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TooManyAttempts
{
    public function __construct(private SecurityEventLogger $logger) {}

    /**
     * @param  array<string, mixed>  $headers
     */
    public function toResponse(Request $request, array $headers, ?string $message = null): Response
    {
        $retry = (int) ($headers['Retry-After'] ?? 60);
        $message = $this->message($message, $retry);

        $this->logger->rateLimited($request, $retry);

        $errors = ['rate_limit' => $message];
        if ($request->is('login') || $request->routeIs('login')) {
            $errors['email'] = $message;
        }
        if ($request->is('forgot-password', 'reset-password') || $request->routeIs('password.email', 'password.store')) {
            $errors['email'] = $message;
        }
        if ($request->is('confirm-password') || $request->routeIs('password.confirm')) {
            $errors['password'] = $message;
        }

        if ($request->header('X-Inertia') || $request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => collect($errors)->map(fn (string $item) => [$item])->all(),
            ], 429, $headers);
        }

        if ($request->hasSession() && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $redirect = redirect()
                ->back()
                ->withInput($request->except(['password', 'password_confirmation', 'current_password', 'token', '_token']))
                ->withErrors($errors)
                ->with('error', $message);
            $redirect->setStatusCode(429);
            foreach ($headers as $name => $value) {
                $redirect->headers->set($name, (string) $value);
            }

            return $redirect;
        }

        return response()
            ->view('errors.429', ['message' => $message, 'retryAfter' => $retry], 429)
            ->withHeaders($headers);
    }

    public function message(?string $provided, int $retryAfter): string
    {
        $provided = is_string($provided) ? trim($provided) : '';
        if ($provided !== '' && strcasecmp($provided, 'Too Many Attempts.') !== 0) {
            return $provided;
        }

        $seconds = max(1, $retryAfter);

        return trans('security.rate_limited', ['seconds' => $seconds]);
    }
}
