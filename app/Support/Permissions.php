<?php

namespace App\Support;

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
            'Purchase Orders' => ['view' => 'purchase_orders.view', 'create' => 'purchase_orders.create', 'edit' => 'purchase_orders.edit', 'delete' => 'purchase_orders.delete', 'submit' => 'purchase_orders.submit', 'approve' => 'purchase_orders.approve', 'reject' => 'purchase_orders.reject', 'print' => 'purchase_orders.print'],
            'Work Orders' => ['view' => 'work_orders.view', 'create' => 'work_orders.create', 'edit' => 'work_orders.edit', 'submit' => 'work_orders.submit', 'approve' => 'work_orders.approve', 'reject' => 'work_orders.reject', 'issue' => 'work_orders.issue', 'execute' => 'work_orders.execute', 'amend' => 'work_orders.amend', 'cancel' => 'work_orders.cancel', 'print' => 'work_orders.print'],
            'Vendors' => ['view' => 'vendors.view', 'create' => 'vendors.create', 'edit' => 'vendors.edit', 'print' => 'vendors.print'],
            'Store' => ['view' => 'store.view', 'create' => 'store.create', 'edit' => 'store.edit', 'submit' => 'store.submit', 'print' => 'store.print'],
            'GRN' => ['view' => 'grn.view', 'create' => 'grn.create', 'edit' => 'grn.edit', 'submit' => 'grn.submit', 'approve' => 'grn.approve', 'reject' => 'grn.reject', 'post' => 'grn.post', 'print' => 'grn.print'],
            'Inventory' => ['view' => 'inventory.view', 'create' => 'inventory.create', 'edit' => 'inventory.edit', 'approve' => 'inventory.approve', 'post' => 'inventory.post', 'adjust' => 'inventory.adjust', 'print' => 'inventory.print'],
            'Internal Delivery Notes' => ['view' => 'idn.view', 'create' => 'idn.create', 'edit' => 'idn.edit', 'submit' => 'idn.submit', 'approve' => 'idn.approve', 'reject' => 'idn.reject', 'dispatch' => 'idn.dispatch', 'receive' => 'idn.receive', 'print' => 'idn.print'],
            'Purchase Invoices' => ['view' => 'invoices.view', 'create' => 'invoices.create', 'edit' => 'invoices.edit', 'submit' => 'invoices.submit', 'approve' => 'invoices.approve', 'print' => 'invoices.print'],
            'Vendor Payments' => ['view' => 'payments.view', 'create' => 'payments.create', 'edit' => 'payments.edit', 'approve' => 'payments.approve'],
            'Users' => ['view' => 'users.view', 'create' => 'users.create', 'edit' => 'users.edit'],
            'Roles & Permissions' => ['view' => 'roles.view', 'create' => 'roles.create', 'edit' => 'roles.edit'],
            'Settings' => ['view' => 'settings.view', 'edit' => 'settings.edit'],
            'Reports' => ['view' => 'reports.view', 'print' => 'reports.print'],
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
            ],
            'purchase_manager' => [
                'dashboard.view', 'projects.view', 'boq.view', 'boq.print',
                'purchase_requests.view', 'purchase_requests.create', 'purchase_requests.edit', 'purchase_requests.submit', 'purchase_requests.approve', 'purchase_requests.reject', 'purchase_requests.print',
                'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.edit', 'purchase_orders.submit', 'purchase_orders.approve', 'purchase_orders.reject', 'purchase_orders.print',
                'vendors.view', 'vendors.create', 'vendors.edit', 'vendors.print',
                'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.print',
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
                'purchase_orders.view', 'purchase_orders.approve', 'purchase_orders.reject', 'purchase_orders.print',
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
                'dashboard.view', 'projects.view', 'vendors.view', 'vendors.print',
                'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.submit', 'invoices.approve', 'invoices.print',
                'payments.view', 'payments.create', 'payments.edit', 'payments.approve',
                'work_orders.view', 'work_orders.print',
                'reports.view', 'reports.print',
            ],
            'viewer' => $viewPrint,
        ];
    }
}
