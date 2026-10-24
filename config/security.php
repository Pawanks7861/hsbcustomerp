<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Only honor X-Forwarded-* headers from these addresses. Leave empty
    | unless the app sits behind a known load balancer or reverse proxy.
    | Use a comma-separated list, or * when every hop is trusted.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Login lockouts and failed-attempt windows
    |--------------------------------------------------------------------------
    |
    | Temporary lockouts are cache-backed and never change users.status.
    | Inactive / suspended accounts remain a separate business control.
    |
    | Production: use a shared CACHE_STORE (database or redis) so limits
    | remain effective across PHP workers and servers. The `array` driver
    | is process-local and is only appropriate for tests.
    |
    */

    'login' => [
        'max_attempts' => max(1, (int) env('SECURITY_LOGIN_MAX_ATTEMPTS', 5)),
        'decay_seconds' => max(60, (int) env('SECURITY_LOGIN_DECAY_SECONDS', 900)),
        'lockout_seconds' => max(60, (int) env('SECURITY_LOGIN_LOCKOUT_SECONDS', 900)),
        'ip_max_attempts' => max(1, (int) env('SECURITY_LOGIN_IP_MAX_ATTEMPTS', 20)),
    ],

    'password_reset' => [
        'max_attempts' => max(1, (int) env('SECURITY_PASSWORD_RESET_MAX_ATTEMPTS', 3)),
        'decay_minutes' => max(1, (int) env('SECURITY_PASSWORD_RESET_DECAY_MINUTES', 15)),
        'ip_max_attempts' => max(1, (int) env('SECURITY_PASSWORD_RESET_IP_MAX_ATTEMPTS', 10)),
        'submit_max_attempts' => max(1, (int) env('SECURITY_PASSWORD_RESET_SUBMIT_MAX_ATTEMPTS', 5)),
        'submit_decay_minutes' => max(1, (int) env('SECURITY_PASSWORD_RESET_SUBMIT_DECAY_MINUTES', 15)),
    ],

    'password_confirm' => [
        'max_attempts' => max(1, (int) env('SECURITY_PASSWORD_CONFIRM_MAX_ATTEMPTS', 5)),
        'decay_minutes' => max(1, (int) env('SECURITY_PASSWORD_CONFIRM_DECAY_MINUTES', 1)),
    ],

    'password_update' => [
        'max_attempts' => max(1, (int) env('SECURITY_PASSWORD_UPDATE_MAX_ATTEMPTS', 5)),
        'decay_minutes' => max(1, (int) env('SECURITY_PASSWORD_UPDATE_DECAY_MINUTES', 1)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Named HTTP rate limiters (see App\Providers\SecurityServiceProvider)
    |--------------------------------------------------------------------------
    |
    | Authenticated navigation stays high enough for ordinary ERP work.
    | Imports, exports, PDFs, chat, and financial writes are stricter.
    |
    */

    'throttle' => [
        'login_ip' => max(1, (int) env('SECURITY_THROTTLE_LOGIN_IP', 10)),
        'authenticated' => max(30, (int) env('SECURITY_THROTTLE_AUTHENTICATED', 240)),
        'writes' => max(10, (int) env('SECURITY_THROTTLE_WRITES', 60)),
        'sensitive' => max(5, (int) env('SECURITY_THROTTLE_SENSITIVE', 20)),
        'imports' => max(1, (int) env('SECURITY_THROTTLE_IMPORTS', 3)),
        'exports' => max(1, (int) env('SECURITY_THROTTLE_EXPORTS', 10)),
        'pdfs' => max(1, (int) env('SECURITY_THROTTLE_PDFS', 15)),
        'assistant' => max(1, (int) env('SECURITY_THROTTLE_ASSISTANT', 20)),
        'assistant_service' => max(1, (int) env('SECURITY_THROTTLE_ASSISTANT_SERVICE', 30)),
    ],

    'uploads' => [
        'spreadsheet_max_bytes' => max(1024, (int) env('SECURITY_SPREADSHEET_MAX_BYTES', 10_485_760)),
        'spreadsheet_max_rows' => max(1, (int) env('SECURITY_SPREADSHEET_MAX_ROWS', 5000)),
    ],

];
