<?php

namespace App\Services;

use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Permissions;

class Access
{
    public function isSuperAdmin(User $user): bool
    {
        return $user->is_admin || $user->hasRoleSlug('super_admin');
    }

    public function canViewAuditLog(User $user): bool
    {
        return $this->canConfigureApprovals($user);
    }

    public function canConfigureApprovals(User $user): bool
    {
        return $this->isSuperAdmin($user) || $user->hasRoleSlug('administrator');
    }

    public function allows(User $user, string $permission): bool
    {
        return $this->explain($user, $permission)['effective'];
    }

    /**
     * @return array{effective: bool, source: string, mode: string}
     */
    public function explain(User $user, string $permission): array
    {
        $user->loadMissing('roles.permissions', 'permissionOverrides');

        if ($this->isSuperAdmin($user)) {
            return ['effective' => true, 'source' => 'Granted by Role', 'mode' => 'inherit'];
        }

        $override = $user->permissionOverrides->firstWhere('permission', $permission);

        if (Permissions::isApproval($permission)) {
            $approver = $override?->effect === UserPermissionOverride::ALLOW;

            return [
                'effective' => $approver,
                'source' => $approver ? 'Approval Settings' : 'Not an approver',
                'mode' => $approver ? 'allow' : 'inherit',
            ];
        }

        if ($override?->effect === UserPermissionOverride::DENY) {
            return ['effective' => false, 'source' => 'Individually Denied', 'mode' => 'deny'];
        }

        if ($override?->effect === UserPermissionOverride::ALLOW) {
            return ['effective' => true, 'source' => 'Individually Allowed', 'mode' => 'allow'];
        }

        if ($user->roles->isNotEmpty()) {
            $granting = $user->roles->first(fn ($role) => $role->grants($permission));

            return [
                'effective' => $granting !== null,
                'source' => $granting ? 'Granted by '.$granting->name : 'Not Granted',
                'mode' => 'inherit',
            ];
        }

        return [
            'effective' => false,
            'source' => 'No role assigned',
            'mode' => 'inherit',
        ];
    }

    /**
     * @return list<string>
     */
    public function grantedNames(User $user): array
    {
        return array_values(array_filter(
            Permissions::all(),
            fn (string $permission) => $this->allows($user, $permission)
        ));
    }

    public function seesAllProjects(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return ($user->project_access ?? 'all') !== 'selected';
    }

    public function canAccessProject(User $user, Project $project): bool
    {
        if ($this->seesAllProjects($user)) {
            return true;
        }

        return $user->projects()->whereKey($project->id)->exists();
    }

    public function abortUnlessCanAccessProject(User $user, ?Project $project): void
    {
        if ($project === null) {
            return;
        }

        abort_unless($this->canAccessProject($user, $project), 404);
    }

    public function abortUnlessVisibleProjectId(User $user, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $project = Project::query()->find($projectId);
        abort_unless($project instanceof Project && $this->canAccessProject($user, $project), 404);
    }

    /**
     * @return list<int>|null Null means every project is visible.
     */
    public function visibleProjectIds(User $user): ?array
    {
        if ($this->seesAllProjects($user)) {
            return null;
        }

        return $user->projects()->pluck('projects.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return array{project_id: int|null, project_ids: list<int>|null}
     */
    public function projectScope(User $user, ?int $projectId): array
    {
        $this->abortUnlessVisibleProjectId($user, $projectId);

        if ($projectId !== null || $this->seesAllProjects($user)) {
            return ['project_id' => $projectId, 'project_ids' => null];
        }

        return ['project_id' => null, 'project_ids' => $this->visibleProjectIds($user) ?? []];
    }

    public function abortUnlessCanAccessLocation(User $user, InventoryLocation $location): void
    {
        if ($location->project_id) {
            $project = $location->project ?? Project::query()->find($location->project_id);
            abort_unless($project instanceof Project && $this->canAccessProject($user, $project), 404);

            return;
        }

        abort_unless($this->seesAllProjects($user), 404);
    }

    public function abortUnlessCanResolveTransfer(User $user, InternalDeliveryNote $note): void
    {
        $projectIds = array_values(array_unique(array_filter([
            $note->source_project_id ? (int) $note->source_project_id : null,
            $note->destination_project_id ? (int) $note->destination_project_id : null,
        ])));

        if ($projectIds === []) {
            abort_unless($this->seesAllProjects($user), 404);

            return;
        }

        foreach ($projectIds as $projectId) {
            $project = Project::query()->find($projectId);
            if ($project instanceof Project && $this->canAccessProject($user, $project)) {
                return;
            }
        }

        abort(404);
    }
}
