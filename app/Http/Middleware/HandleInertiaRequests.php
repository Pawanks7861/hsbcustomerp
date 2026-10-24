<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Keep exception-handler error locations instead of reloading the forbidden URL
     * (which would still be blocked, and used to surface as an Inertia HTML modal).
     */
    public function onVersionChange(Request $request, Response $response): Response
    {
        $location = $response->headers->get(Header::LOCATION);

        if (is_string($location) && str_contains($location, '/error/')) {
            return $response;
        }

        return parent::onVersionChange($request, $response);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => (bool) $user->is_admin,
                    'permissions' => app(\App\Services\Access::class)->grantedNames($user),
                ] : null,
            ],
            'approvals' => fn () => $user ? [
                'can_approve' => app(\App\Services\ApprovalInbox::class)->typesFor($user) !== [],
                'pending' => app(\App\Services\ApprovalInbox::class)->count($user),
                'can_configure' => app(\App\Services\Access::class)->canConfigureApprovals($user),
            ] : null,
            'menu_tutorial' => function () use ($user) {
                if (! $user || $user->menu_tutorial_completed_at) {
                    return null;
                }

                $access = app(\App\Services\Access::class);
                $user->loadMissing('roles');

                if ($access->isSuperAdmin($user) || $user->hasRoleSlug('administrator')) {
                    return null;
                }

                return [
                    'roles' => $user->roles->sortBy('name')->pluck('name')->values()->all(),
                    'menus' => \App\Support\RoleMenus::forPermissions($access->grantedNames($user)),
                ];
            },
            'company' => fn () => \App\Models\CompanySetting::shared(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
