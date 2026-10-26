<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleByOperation
{
    public function handle(Request $request, Closure $next): Response
    {
        return app(ThrottleRequests::class)->handle($request, $next, $this->limiterFor($request));
    }

    private function limiterFor(Request $request): string
    {
        $name = (string) $request->route()?->getName();
        $mutating = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($name === 'global-search') {
            return 'search';
        }

        if ($name === 'assistant.ask' || str_starts_with($name, 'assistant.')) {
            return $mutating ? 'assistant' : 'authenticated';
        }

        if ($mutating && str_contains($name, 'import')) {
            return 'imports';
        }

        if (str_contains($name, 'export')) {
            return 'exports';
        }

        if (str_contains($name, '.pdf') || str_ends_with($name, 'pdf') || str_contains($name, 'pdf.')) {
            return 'pdfs';
        }

        if ($mutating && preg_match('/approve|reject|payment|recovery|stock-adjustment/', $name) === 1) {
            return 'sensitive';
        }

        if ($mutating) {
            return 'writes';
        }

        return 'authenticated';
    }
}
