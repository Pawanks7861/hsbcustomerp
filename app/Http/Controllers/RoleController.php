<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\RolePermission;
use App\Services\Access;
use App\Services\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private Access $access, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $roles = Role::query()->withCount('users')->orderBy('name')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_active' => $role->is_active,
            'users_count' => $role->users_count,
        ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'canManage' => $this->access->allows(auth()->user(), 'roles.edit'),
        ]);
    }

    public function create(): Response
    {
        abort_unless($this->access->allows(auth()->user(), 'roles.create'), 403);

        return Inertia::render('Roles/Form', [
            'role' => null,
            'modules' => $this->matrix([]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'roles.create'), 403);
        $data = $this->validated($request);
        $role = Role::query()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'is_active' => $request->boolean('is_active', true),
        ]);
        $this->sync($request, $role);
        $this->activity->log($request->user()->id, 'role.created', $role);

        return redirect()->route('roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role): Response
    {
        abort_unless($this->access->allows(auth()->user(), 'roles.edit'), 403);
        $role->load('permissions', 'users');

        return Inertia::render('Roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'is_system' => $role->is_system,
                'users' => $role->users->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]),
            ],
            'modules' => $this->matrix($role->permissions->pluck('permission')->all()),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'roles.edit'), 403);
        abort_if($role->slug === 'super_admin' && ! $request->user()->isSuperAdmin(), 403);
        $data = $this->validated($request, $role);
        $role->update([
            'name' => $role->slug === 'super_admin' ? $role->name : $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $role->slug === 'super_admin' ? true : $request->boolean('is_active'),
        ]);
        if ($role->slug !== 'super_admin') {
            $this->sync($request, $role);
        }
        $this->activity->log($request->user()->id, 'role.updated', $role);

        return redirect()->route('roles.index')->with('success', 'Role updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);
    }

    private function sync(Request $request, Role $role): void
    {
        $actor = $request->user();
        $role->permissions()->delete();

        foreach ($request->input('permissions', []) as $permission) {
            if (! Permissions::exists($permission) || Permissions::isApproval($permission)) {
                continue;
            }

            if (! $actor->isSuperAdmin() && ! $this->access->allows($actor, $permission)) {
                continue;
            }

            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }
    }

    /**
     * @param  list<string>  $granted
     * @return list<array<string, mixed>>
     */
    private function matrix(array $granted): array
    {
        $rows = [];

        foreach (Permissions::modules() as $module => $actions) {
            $items = [];

            foreach ($actions as $action => $permission) {
                $items[] = [
                    'action' => $action,
                    'permission' => $permission,
                    'granted' => in_array($permission, $granted, true),
                    'approval' => Permissions::isApproval($permission),
                ];
            }

            $rows[] = ['module' => $module, 'actions' => $items];
        }

        return $rows;
    }
}
