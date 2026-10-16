<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_invoices', 'round_off')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->decimal('round_off', 12, 2)->default(0)->after('tax_amount');
            });
        }

        $permissions = [
            'purchase_orders.edit_rates',
            'purchase_orders.view_rates',
            'purchase_orders.view_rate_history',
            'invoices.round_off',
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
            'expenses.approve',
            'expenses.reject',
            'expenses.print',
            'expenses.manage_categories',
        ];

        $roleIds = DB::table('roles')->whereIn('slug', ['super_admin', 'administrator', 'purchase_manager'])->pluck('id');

        foreach ($roleIds as $roleId) {
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
        Schema::table('purchase_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_invoices', 'round_off')) {
                $table->dropColumn('round_off');
            }
        });
    }
};
