<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectAccess
{
    public function __construct(private Access $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project && $request->user()) {
            abort_unless($this->access->canAccessProject($request->user(), $project), 404);
        }

        return $next($request);
    }
}
