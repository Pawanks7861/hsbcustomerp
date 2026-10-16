<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return array<string, list<string>>
     */
    private function grantsByRole(): array
    {
        return [
            'accounts' => [
                'debit_notes.view',
                'debit_notes.create',
                'debit_notes.edit',
                'debit_notes.submit',
                'debit_notes.approve',
                'debit_notes.reject',
                'debit_notes.print',
                'credit_notes.view',
                'credit_notes.create',
                'credit_notes.edit',
                'credit_notes.submit',
                'credit_notes.approve',
                'credit_notes.reject',
                'credit_notes.print',
                'expenses.view',
                'expenses.create',
                'expenses.edit',
                'expenses.submit',
                'expenses.print',
                'invoices.round_off',
            ],
            'purchase_manager' => [
                'debit_notes.view',
                'debit_notes.create',
                'debit_notes.edit',
                'debit_notes.submit',
                'debit_notes.print',
                'credit_notes.view',
                'credit_notes.create',
                'credit_notes.edit',
                'credit_notes.submit',
                'credit_notes.print',
                'expenses.view',
            ],
            'project_manager' => [
                'expenses.view',
                'debit_notes.view',
                'credit_notes.view',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->grantsByRole() as $slug => $permissions) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission' => $permission,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Permissions remain; down is intentionally empty to avoid stripping live role grants.
    }
};
