<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const APPROVALS = [
        'purchase_requests.approve', 'purchase_requests.reject',
        'purchase_orders.approve', 'purchase_orders.reject',
        'work_orders.approve', 'work_orders.reject',
        'grn.post', 'grn.reject',
        'inventory.post',
        'inventory.adjust',
        'idn.approve', 'idn.reject',
    ];

    public function up(): void
    {
        $superAdminRole = DB::table('roles')->where('slug', 'super_admin')->value('id');
        $now = now();

        $users = DB::table('users')
            ->whereNotNull('role_id')
            ->where('is_admin', false)
            ->when($superAdminRole, fn ($query) => $query->where('role_id', '!=', $superAdminRole))
            ->get(['id', 'role_id']);

        foreach ($users as $user) {
            $granted = DB::table('role_permissions')
                ->where('role_id', $user->role_id)
                ->whereIn('permission', self::APPROVALS)
                ->pluck('permission');

            foreach ($granted as $permission) {
                $override = DB::table('user_permission_overrides')
                    ->where('user_id', $user->id)
                    ->where('permission', $permission)
                    ->first();

                if ($override === null) {
                    DB::table('user_permission_overrides')->insert([
                        'user_id' => $user->id,
                        'permission' => $permission,
                        'effect' => 'allow',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        DB::table('user_permission_overrides')
            ->whereIn('permission', self::APPROVALS)
            ->where('effect', 'deny')
            ->delete();

        DB::table('role_permissions')
            ->whereIn('permission', self::APPROVALS)
            ->when($superAdminRole, fn ($query) => $query->where('role_id', '!=', $superAdminRole))
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
