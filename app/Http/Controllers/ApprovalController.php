<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Access;
use App\Services\ActivityLogger;
use App\Services\ApprovalInbox;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function __construct(
        private ApprovalInbox $inbox,
        private Access $access,
        private ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $type = $request->string('type')->toString();
        $type = array_key_exists($type, ApprovalInbox::types()) ? $type : null;
        $counts = $this->inbox->counts($user);

        return Inertia::render('Approvals/Index', [
            'filters' => [...Listing::filters($request), 'type' => $type],
            'types' => collect($counts)->map(fn (int $count, string $key) => [
                'key' => $key,
                'label' => ApprovalInbox::types()[$key]['label'],
                'action' => ApprovalInbox::types()[$key]['action'],
                'count' => $count,
            ])->values(),
            'items' => Listing::paginateRows($this->inbox->items($user, $type), $request, [
                'number', 'type_label', 'project.code', 'project.name', 'date', 'summary', 'flag', 'requested_by', 'amount', 'waiting_since',
            ]),
            'canConfigure' => $this->access->canConfigureApprovals($user),
        ]);
    }

    public function settings(Request $request): Response
    {
        abort_unless($this->access->canConfigureApprovals($request->user()), 403);

        $staff = $this->staff();

        return Inertia::render('Approvals/Settings', [
            'staff' => $staff->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'designation' => $user->designation,
                'role' => $user->roles->sortBy('name')->pluck('name')->join(', ') ?: null,
                'super_admin' => $this->access->isSuperAdmin($user),
            ])->values(),
            'types' => collect(ApprovalInbox::types())->map(fn (array $definition, string $key) => [
                'key' => $key,
                'label' => $definition['label'],
                'action' => $definition['action'],
                'description' => $definition['description'],
                'users' => $staff
                    ->filter(fn (User $user) => $this->access->allows($user, $definition['permissions'][0]))
                    ->pluck('id')
                    ->values(),
            ])->values(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->access->canConfigureApprovals($actor), 403);

        $data = $request->validate([
            'types' => ['required', 'array'],
            'types.*.key' => ['required', 'string', Rule::in(array_keys(ApprovalInbox::types()))],
            'types.*.users' => ['present', 'array'],
            'types.*.users.*' => ['integer', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($data): void {
            $staff = $this->staff()->reject(fn (User $user) => $this->access->isSuperAdmin($user));

            foreach ($data['types'] as $row) {
                $approvers = array_map('intval', $row['users']);

                foreach (ApprovalInbox::types()[$row['key']]['permissions'] as $permission) {
                    foreach ($staff as $user) {
                        $query = UserPermissionOverride::query()->where('user_id', $user->id)->where('permission', $permission);

                        if (in_array($user->id, $approvers, true)) {
                            UserPermissionOverride::query()->updateOrCreate(
                                ['user_id' => $user->id, 'permission' => $permission],
                                ['effect' => UserPermissionOverride::ALLOW],
                            );
                        } else {
                            $query->delete();
                        }
                    }
                }
            }
        });

        $this->activity->log($actor->id, 'approval_settings.updated', null, [
            'types' => collect($data['types'])->mapWithKeys(fn ($row) => [$row['key'] => $row['users']])->all(),
        ]);

        return redirect()->route('approvals.settings')->with('success', 'Approval settings saved.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function staff()
    {
        return User::query()
            ->with(['roles', 'permissionOverrides'])
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }
}
