<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Permissions;

class Access
{
    public function isSuperAdmin(User $user): bool
    {
        $user->loadMissing('role');

        return $user->is_admin || $user->role?->slug === 'super_admin';
    }

    public function canViewAuditLog(User $user): bool
    {
        return $this->canConfigureApprovals($user);
    }

    public function canConfigureApprovals(User $user): bool
    {
        $user->loadMissing('role');

        return $this->isSuperAdmin($user) || $user->role?->slug === 'administrator';
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
        $user->loadMissing('role.permissions', 'permissionOverrides');

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

        if ($user->role_id) {
            $granted = $user->role?->grants($permission) ?? false;

            return [
                'effective' => $granted,
                'source' => $granted ? 'Granted by Role' : 'Not Granted',
                'mode' => 'inherit',
            ];
        }

        $legacy = in_array($permission, Permissions::legacy(), true);

        return [
            'effective' => $legacy,
            'source' => $legacy ? 'Granted by Role' : 'Not Granted',
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
}
