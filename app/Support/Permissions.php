<?php

namespace App\Support;

use App\Services\ApprovalInbox;

class Permissions
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function modules(): array
    {
        return [
            'Dashboard' => ['view' => 'dashboard.view', 'print' => 'dashboard.print'],
            'Projects' => ['view' => 'projects.view', 'create' => 'projects.create', 'edit' => 'projects.edit', 'delete' => 'projects.delete', 'print' => 'projects.print'],
            'BOQ' => ['view' => 'boq.view', 'create' => 'boq.create', 'edit' => 'boq.edit', 'delete' => 'boq.delete', 'submit' => 'boq.submit', 'approve' => 'boq.approve', 'print' => 'boq.print'],
            'Purchase Requests' => ['view' => 'purchase_requests.view', 'create' => 'purchase_requests.create', 'edit' => 'purchase_requests.edit', 'delete' => 'purchase_requests.delete', 'submit' => 'purchase_requests.submit', 'approve' => 'purchase_requests.approve', 'reject' => 'purchase_requests.reject', 'print' => 'purchase_requests.print'],
            'Purchase Orders' => [
                'view' => 'purchase_orders.view',
                'create' => 'purchase_orders.create',
                'edit' => 'purchase_orders.edit',
                'edit_rates' => 'purchase_orders.edit_rates',
                'view_rates' => 'purchase_orders.view_rates',
                'view_rate_history' => 'purchase_orders.view_rate_history',
                'request_rate_amendment' => 'purchase_orders.request_rate_amendment',
                'approve_rate_amendment' => 'purchase_orders.approve_rate_amendment',
                'reject_rate_amendment' => 'purchase_orders.reject_rate_amendment',
                'delete' => 'purchase_orders.delete',
                'submit' => 'purchase_orders.submit',
                'approve' => 'purchase_orders.approve',
                'reject' => 'purchase_orders.reject',
                'print' => 'purchase_orders.print',
            ],
            'Work Orders' => ['view' => 'work_orders.view', 'create' => 'work_orders.create', 'edit' => 'work_orders.edit', 'submit' => 'work_orders.submit', 'approve' => 'work_orders.approve', 'reject' => 'work_orders.reject', 'issue' => 'work_orders.issue', 'execute' => 'work_orders.execute', 'amend' => 'work_orders.amend', 'cancel' => 'work_orders.cancel', 'print' => 'work_orders.print'],
            'Vendors' => ['view' => 'vendors.view', 'create' => 'vendors.create', 'edit' => 'vendors.edit', 'ledger' => 'vendors.ledger', 'print' => 'vendors.print'],
            'Store' => ['view' => 'store.view', 'create' => 'store.create', 'edit' => 'store.edit', 'submit' => 'store.submit', 'print' => 'store.print'],
            'GRN' => ['view' => 'grn.view', 'create' => 'grn.create', 'edit' => 'grn.edit', 'submit' => 'grn.submit', 'approve' => 'grn.approve', 'reject' => 'grn.reject', 'post' => 'grn.post', 'print' => 'grn.print'],
            'Inventory' => ['view' => 'inventory.view', 'create' => 'inventory.create', 'edit' => 'inventory.edit', 'approve' => 'inventory.approve', 'post' => 'inventory.post', 'adjust' => 'inventory.adjust', 'print' => 'inventory.print'],
            'Internal Delivery Notes' => ['view' => 'idn.view', 'create' => 'idn.create', 'edit' => 'idn.edit', 'submit' => 'idn.submit', 'approve' => 'idn.approve', 'reject' => 'idn.reject', 'dispatch' => 'idn.dispatch', 'receive' => 'idn.receive', 'print' => 'idn.print'],
            'Purchase Invoices' => [
                'view' => 'invoices.view',
                'create' => 'invoices.create',
                'edit' => 'invoices.edit',
                'submit' => 'invoices.submit',
                'approve' => 'invoices.approve',
                'delete' => 'invoices.delete',
                'round_off' => 'invoices.round_off',
                'print' => 'invoices.print',
            ],
            'Debit Notes' => [
                'view' => 'debit_notes.view',
                'create' => 'debit_notes.create',
                'edit' => 'debit_notes.edit',
                'submit' => 'debit_notes.submit',
                'approve' => 'debit_notes.approve',
                'reject' => 'debit_notes.reject',
                'print' => 'debit_notes.print',
            ],
            'Credit Notes' => [
                'view' => 'credit_notes.view',
                'create' => 'credit_notes.create',
                'edit' => 'credit_notes.edit',
                'submit' => 'credit_notes.submit',
                'approve' => 'credit_notes.approve',
                'reject' => 'credit_notes.reject',
                'print' => 'credit_notes.print',
            ],
            'Expenses' => [
                'view' => 'expenses.view',
                'create' => 'expenses.create',
                'edit' => 'expenses.edit',
                'submit' => 'expenses.submit',
                'approve' => 'expenses.approve',
                'reject' => 'expenses.reject',
                'print' => 'expenses.print',
                'manage_categories' => 'expenses.manage_categories',
            ],
            'Vendor Payments' => ['view' => 'payments.view', 'create' => 'payments.create', 'edit' => 'payments.edit', 'approve' => 'payments.approve'],
            'Users' => ['view' => 'users.view', 'create' => 'users.create', 'edit' => 'users.edit'],
            'Roles & Permissions' => ['view' => 'roles.view', 'create' => 'roles.create', 'edit' => 'roles.edit'],
            'Settings' => ['view' => 'settings.view', 'edit' => 'settings.edit'],
            'Reports' => ['view' => 'reports.view', 'export' => 'reports.export', 'print' => 'reports.print'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::modules() as $actions) {
            foreach ($actions as $permission) {
                $names[] = $permission;
            }
        }

        return $names;
    }

    /**
     * Approval rights are granted per user from Approval Settings, never by role.
     *
     * @return list<string>
     */
    public static function approvals(): array
    {
        return array_values(array_unique(array_merge(
            ...array_column(ApprovalInbox::types(), 'permissions')
        )));
    }

    public static function isApproval(string $permission): bool
    {
        return in_array($permission, self::approvals(), true);
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Permissions a user without a role keeps, matching the previous admin-flag behavior.
     *
     * @return list<string>
     */
    public static function legacy(): array
    {
        $denied = [
            'purchase_requests.approve', 'purchase_requests.reject',
            'purchase_orders.approve', 'purchase_orders.reject',
            'purchase_orders.request_rate_amendment', 'purchase_orders.approve_rate_amendment', 'purchase_orders.reject_rate_amendment',
            'boq.approve',
            'grn.approve', 'grn.reject', 'grn.post',
            'inventory.approve', 'inventory.post', 'inventory.adjust',
            'idn.approve', 'idn.reject', 'idn.dispatch', 'idn.receive',
            'invoices.approve', 'payments.approve',
            'work_orders.approve', 'work_orders.reject', 'work_orders.issue', 'work_orders.amend', 'work_orders.cancel',
            'users.view', 'users.create', 'users.edit',
            'roles.view', 'roles.create', 'roles.edit',
            'settings.view', 'settings.edit',
        ];

        return array_values(array_diff(self::all(), $denied));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roleGrants(): array
    {
        $all = self::all();
        $viewPrint = array_values(array_filter($all, fn (string $permission) => str_ends_with($permission, '.view') || str_ends_with($permission, '.print')));

        return [
            'super_admin' => $all,
            'administrator' => $all,
            'project_manager' => [
                'dashboard.view', 'dashboard.print', 'projects.view', 'projects.create', 'projects.edit', 'projects.print',
                'boq.view', 'boq.create', 'boq.edit', 'boq.submit', 'boq.print',
                'purchase_requests.view', 'purchase_requests.create', 'purchase_requests.edit', 'purchase_requests.submit', 'purchase_requests.print',
                'purchase_orders.view', 'purchase_orders.print',
                'store.view', 'store.print', 'grn.view', 'grn.print',
                'inventory.view', 'inventory.print',
                'idn.view', 'idn.create', 'idn.edit', 'idn.submit', 'idn.approve', 'idn.print',
                'invoices.view', 'invoices.print', 'reports.view', 'reports.print',
                'work_orders.view', 'work_orders.create', 'work_orders.edit', 'work_orders.submit', 'work_orders.approve', 'work_orders.reject', 'work_orders.issue', 'work_orders.execute', 'work_orders.amend', 'work_orders.cancel', 'work_orders.print',
                'expenses.view', 'debit_notes.view', 'credit_notes.view',
            ],
            'purchase_manager' => [
                'dashboard.view', 'projects.view', 'boq.view', 'boq.print',
                'purchase_requests.view', 'purchase_requests.create', 'purchase_requests.edit', 'purchase_requests.submit', 'purchase_requests.approve', 'purchase_requests.reject', 'purchase_requests.print',
                'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.edit', 'purchase_orders.edit_rates', 'purchase_orders.view_rates', 'purchase_orders.view_rate_history', 'purchase_orders.request_rate_amendment', 'purchase_orders.approve_rate_amendment', 'purchase_orders.reject_rate_amendment', 'purchase_orders.submit', 'purchase_orders.approve', 'purchase_orders.reject', 'purchase_orders.print',
                'vendors.view', 'vendors.create', 'vendors.edit', 'vendors.print',
                'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.print',
                'debit_notes.view', 'debit_notes.create', 'debit_notes.edit', 'debit_notes.submit', 'debit_notes.print',
                'credit_notes.view', 'credit_notes.create', 'credit_notes.edit', 'credit_notes.submit', 'credit_notes.print',
                'expenses.view',
                'reports.view', 'reports.print',
            ],
            'purchase_requester' => [
                'dashboard.view', 'projects.view', 'boq.view',
                'purchase_requests.view', 'purchase_requests.create', 'purchase_requests.edit', 'purchase_requests.submit', 'purchase_requests.print',
                'purchase_orders.view',
            ],
            'purchase_approver' => [
                'dashboard.view', 'projects.view', 'boq.view',
                'purchase_requests.view', 'purchase_requests.approve', 'purchase_requests.reject', 'purchase_requests.print',
                'purchase_orders.view', 'purchase_orders.approve', 'purchase_orders.reject', 'purchase_orders.approve_rate_amendment', 'purchase_orders.reject_rate_amendment', 'purchase_orders.view_rate_history', 'purchase_orders.print',
            ],
            'store_manager' => [
                'dashboard.view', 'projects.view',
                'store.view', 'store.create', 'store.edit', 'store.submit', 'store.print',
                'grn.view', 'grn.create', 'grn.edit', 'grn.submit', 'grn.approve', 'grn.reject', 'grn.post', 'grn.print',
                'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.approve', 'inventory.post', 'inventory.adjust', 'inventory.print',
                'idn.view', 'idn.create', 'idn.edit', 'idn.submit', 'idn.approve', 'idn.reject', 'idn.dispatch', 'idn.receive', 'idn.print',
                'reports.view', 'reports.print',
            ],
            'storekeeper' => [
                'dashboard.view', 'projects.view',
                'store.view', 'store.create', 'store.edit', 'store.submit', 'store.print',
                'grn.view', 'grn.create', 'grn.edit', 'grn.submit', 'grn.print',
                'inventory.view', 'inventory.create', 'inventory.print',
                'idn.view', 'idn.create', 'idn.edit', 'idn.submit', 'idn.dispatch', 'idn.receive', 'idn.print',
            ],
            'site_engineer' => [
                'dashboard.view', 'projects.view', 'boq.view',
                'purchase_requests.view', 'purchase_requests.create', 'purchase_requests.submit', 'purchase_requests.print',
                'inventory.view', 'inventory.print',
                'idn.view', 'idn.receive', 'idn.print',
                'work_orders.view', 'work_orders.create', 'work_orders.edit', 'work_orders.submit', 'work_orders.execute', 'work_orders.print',
            ],
            'accounts' => [
                'dashboard.view', 'projects.view', 'vendors.view', 'vendors.ledger', 'vendors.print',
                'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.submit', 'invoices.approve', 'invoices.print', 'invoices.round_off',
                'payments.view', 'payments.create', 'payments.edit', 'payments.approve',
                'debit_notes.view', 'debit_notes.create', 'debit_notes.edit', 'debit_notes.submit', 'debit_notes.approve', 'debit_notes.reject', 'debit_notes.print',
                'credit_notes.view', 'credit_notes.create', 'credit_notes.edit', 'credit_notes.submit', 'credit_notes.approve', 'credit_notes.reject', 'credit_notes.print',
                'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.submit', 'expenses.approve', 'expenses.print',
                'work_orders.view', 'work_orders.print',
                'reports.view', 'reports.export', 'reports.print',
            ],
            'viewer' => $viewPrint,
        ];
    }
}
