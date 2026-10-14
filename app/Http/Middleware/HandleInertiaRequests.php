<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

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
            'company' => fn () => \App\Models\CompanySetting::shared(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
