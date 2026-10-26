<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Access;
use App\Services\ActivityLogger;
use App\Support\Listing;
use App\Support\Permissions;
use App\Support\RoleMenus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private Access $access, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $filters = Listing::filters($request);
        $query = User::query()
            ->with(['roles', 'projects'])
            ->orderBy('name')
            ->orderBy('id');
        Listing::applySearch($query, $filters['search'], [
            'name', 'email', 'phone', 'designation', 'status', 'last_login_at', 'created_at',
            'roles.name', 'projects.project_name',
        ]);

        $users = Listing::paginate($query, $request)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'designation' => $user->designation,
                'role' => $this->roleLabels($user),
                'projects' => $user->project_access === 'selected'
                    ? $user->projects->pluck('project_name')->join(', ')
                    : 'All projects',
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->toDateTimeString(),
                'created_at' => $user->created_at?->toDateString(),
                'is_self' => $user->is($request->user()),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'user' => null,
            'roles' => $this->roleOptions(),
            'projects' => Project::query()->orderBy('project_name')->get(['id', 'project_name']),
            'can_assign_super_admin' => $this->access->isSuperAdmin(request()->user()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->guardPrivilege($request, null);
        $user = User::query()->create($request->safe()->only(['name', 'email', 'phone', 'designation', 'password']));
        $this->applyPrivileges($user, $request);
        $this->syncRoles($request, $user);
        $this->syncProjects($request, $user);
        $this->activity->log($request->user()->id, 'user.created', $user, [
            'email' => $user->email,
            'role_ids' => $user->roles()->pluck('roles.id')->all(),
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        $user->load('projects', 'roles');

        return Inertia::render('Users/Form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'designation' => $user->designation,
                'status' => $user->status,
                'is_admin' => $user->is_admin,
                'role_ids' => $user->roles->pluck('id')->values()->all(),
                'project_access' => $user->project_access ?: 'all',
                'project_ids' => $user->projects->pluck('id'),
                'is_self' => $user->is(auth()->user()),
            ],
            'roles' => $this->roleOptions($user),
            'projects' => Project::query()->orderBy('project_name')->get(['id', 'project_name']),
            'can_assign_super_admin' => $this->access->isSuperAdmin(request()->user()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->is($request->user()) && ! $request->boolean('is_admin')) {
            return back()->withErrors([
                'is_admin' => 'You cannot remove your own admin access.',
            ]);
        }

        if ($user->is($request->user()) && $request->validated('status') === User::STATUS_INACTIVE) {
            return back()->withErrors([
                'status' => 'You cannot deactivate your own account.',
            ]);
        }

        $this->guardPrivilege($request, $user);
        $user->loadMissing('roles');
        $before = [
            'role_ids' => $user->roles->pluck('id')->sort()->values()->all(),
            'is_admin' => $user->is_admin,
            'status' => $user->status,
            'project_access' => $user->project_access,
        ];
        $profile = collect($request->validated())->only(['name', 'email', 'phone', 'designation'])->all();

        if ($request->filled('password')) {
            $profile['password'] = $request->validated('password');
        }

        $user->fill($profile);
        $this->applyPrivileges($user, $request);
        $this->syncRoles($request, $user);
        $this->syncProjects($request, $user);
        $user->load('roles');
        $this->activity->log($request->user()->id, 'user.updated', $user, [
            'before' => $before,
            'after' => [
                'role_ids' => $user->roles->pluck('id')->sort()->values()->all(),
                'is_admin' => $user->is_admin,
                'status' => $user->status,
                'project_access' => $user->project_access,
            ],
            'password_reset' => $request->filled('password'),
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', 'User updated.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $status = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ])['status'];

        if ($user->is($request->user()) && $status === User::STATUS_INACTIVE) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($status === User::STATUS_INACTIVE && $this->isLastSuperAdmin($user)) {
            return back()->with('error', 'The last active Super Admin cannot be deactivated.');
        }

        $user->forceFill(['status' => $status])->save();
        $this->activity->log($request->user()->id, 'user.status', $user, ['status' => $status]);

        return back()->with('success', $status === User::STATUS_ACTIVE ? 'User activated.' : 'User deactivated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->requestedPurchaseRequests()->exists() || $user->createdPurchaseOrders()->exists()) {
            return back()->with('error', 'This user cannot be deleted because procurement records exist.');
        }

        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', 'User deleted.');
    }

    public function permissions(Request $request, User $user): Response
    {
        abort_unless($this->access->allows($request->user(), 'users.edit'), 403);
        $user->load('roles.permissions', 'permissionOverrides');
        $rows = [];

        foreach (Permissions::modules() as $module => $actions) {
            $items = [];

            foreach ($actions as $action => $permission) {
                $explained = $this->access->explain($user, $permission);
                $items[] = [
                    'action' => $action,
                    'label' => Permissions::label($permission),
                    'action_label' => Permissions::actionTitle($action),
                    'permission' => $permission,
                    'mode' => $user->permissionOverrides->firstWhere('permission', $permission)?->effect ?: 'inherit',
                    'effective' => $explained['effective'],
                    'source' => $explained['source'],
                    'approval' => Permissions::isApproval($permission),
                ];
            }

            $rows[] = ['module' => $module, 'actions' => $items];
        }

        return Inertia::render('Users/Permissions', [
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $this->roleLabels($user),
            ],
            'modules' => $rows,
        ]);
    }

    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'users.edit'), 403);

        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot change your own permissions.');
        }

        $data = $request->validate([
            'overrides' => ['required', 'array'],
            'overrides.*.permission' => ['required', 'string'],
            'overrides.*.mode' => ['required', Rule::in(['inherit', 'allow', 'deny'])],
        ]);

        $actor = $request->user();

        foreach ($data['overrides'] as $override) {
            if (! Permissions::exists($override['permission'])) {
                return back()->with('error', 'Unknown permission.');
            }

            if ($override['mode'] === 'allow' && ! $this->access->isSuperAdmin($actor) && ! $this->access->allows($actor, $override['permission'])) {
                return back()->with('error', 'You cannot grant a permission you do not have.');
            }
        }

        $user->permissionOverrides()->whereNotIn('permission', Permissions::approvals())->delete();

        foreach ($data['overrides'] as $override) {
            if ($override['mode'] === 'inherit' || Permissions::isApproval($override['permission'])) {
                continue;
            }

            UserPermissionOverride::query()->create([
                'user_id' => $user->id,
                'permission' => $override['permission'],
                'effect' => $override['mode'],
            ]);
        }

        $user->unsetRelation('permissionOverrides');
        $this->activity->log($actor->id, 'user.permissions', $user, ['overrides' => $data['overrides']]);

        return back()->with('success', 'Individual permissions saved.');
    }

    private function guardPrivilege(Request $request, ?User $user): void
    {
        $actor = $request->user();
        $roleIds = collect($request->input('role_ids', []))->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();

        if ($request->boolean('is_admin') && ! $actor->isSuperAdmin()) {
            abort(403);
        }

        if ($user && $user->is($actor)) {
            $user->loadMissing('roles');
            $current = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($request->boolean('is_admin') !== (bool) $user->is_admin || $roleIds !== $current) {
                abort(403, 'You cannot change your own access.');
            }
        }

        if ($roleIds !== []) {
            $roles = Role::query()->with('permissions')->whereIn('id', $roleIds)->get();

            abort_unless($roles->count() === count($roleIds), 422);

            if ($roles->contains('slug', 'super_admin') && ! $actor->isSuperAdmin()) {
                abort(403);
            }

            if (! $actor->isSuperAdmin()) {
                foreach ($roles as $role) {
                    foreach ($role->permissions as $permission) {
                        abort_unless($this->access->allows($actor, $permission->permission), 403);
                    }
                }
            }
        }

        if ($user && $user->is_admin && ! $request->boolean('is_admin') && $this->isLastSuperAdmin($user)) {
            abort(403, 'The last active Super Admin cannot be removed.');
        }

        if (! $request->boolean('is_admin') && $roleIds === []) {
            abort(422, 'Assign at least one staff role.');
        }
    }

    private function applyPrivileges(User $user, Request $request): void
    {
        $user->forceFill([
            'status' => $request->validated('status'),
            'is_admin' => $request->boolean('is_admin'),
            'project_access' => $request->input('project_access') ?: 'all',
        ])->save();
    }

    private function syncRoles(Request $request, User $user): void
    {
        $user->roles()->sync(
            collect($request->input('role_ids', []))->map(fn ($id) => (int) $id)->filter()->unique()->values()->all()
        );
        $user->unsetRelation('roles');
        $user->unsetRelation('permissionOverrides');
    }

    private function syncProjects(Request $request, User $user): void
    {
        if (! $request->exists('project_access') && ! $request->exists('project_ids')) {
            return;
        }

        if ($request->input('project_access') === 'selected') {
            $user->projects()->sync($request->input('project_ids', []));

            return;
        }

        $user->projects()->detach();
    }

    private function isLastSuperAdmin(User $user): bool
    {
        if (! $user->isSuperAdmin() || ! $user->isActive()) {
            return false;
        }

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->where('is_admin', true)->orWhereHas('roles', fn ($role) => $role->where('slug', 'super_admin'));
            })
            ->count() <= 1;
    }

    private function roleLabels(User $user): string
    {
        $names = $user->roles->sortBy('name')->pluck('name')->values();

        if ($names->isEmpty()) {
            return $user->is_admin ? 'Super Admin' : 'No role';
        }

        return $names->join(', ');
    }

    /**
     * Staff roles for the create/edit form. Super Admin and Administrator are skipped
     * so the menu tutorial only covers ordinary role-based access.
     *
     * @return list<array{id: int, name: string, slug: string, privileged: bool, menus: list<array{group: string, items: list<array{label: string, target: ?string, hint: string, action: string}>}>}>
     */
    private function roleOptions(?User $target = null): array
    {
        $query = Role::query()
            ->with('permissions')
            ->where('is_active', true)
            ->orderBy('name');

        return $query->get()
            ->filter(function (Role $role) use ($target) {
                if (! RoleMenus::isPrivileged($role->slug)) {
                    return true;
                }

                // Keep a privileged role visible only if the user already has it (edit).
                return $target?->roles->contains('slug', $role->slug) === true;
            })
            ->map(function (Role $role) {
                $permissions = $role->permissions->pluck('permission')->all();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'privileged' => RoleMenus::isPrivileged($role->slug),
                    'menus' => RoleMenus::forPermissions($permissions),
                ];
            })
            ->values()
            ->all();
    }
}
