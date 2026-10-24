<?php

namespace App\Http\Middleware;

use App\Support\SafeInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /** @var list<string> */
    private const SKIP = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        '_token',
        'remember',
        'remember_token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $request->merge($this->clean($request->all()));

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array((string) $key, self::SKIP, true) || $value instanceof UploadedFile) {
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->clean($value);

                continue;
            }

            if (is_string($value)) {
                $values[$key] = SafeInput::plainText($value);
            }
        }

        return $values;
    }
}
