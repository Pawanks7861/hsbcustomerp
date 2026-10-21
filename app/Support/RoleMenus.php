<?php

namespace App\Support;

class RoleMenus
{
    /**
     * Privileged roles skipped from the create-user menu tutorial.
     *
     * @return list<string>
     */
    public static function privilegedSlugs(): array
    {
        return ['super_admin', 'administrator'];
    }

    public static function isPrivileged(?string $slug): bool
    {
        return in_array((string) $slug, self::privilegedSlugs(), true);
    }

    /**
     * Sidebar menus shown to staff roles. Admin / Super Admin menus are omitted.
     *
     * @return array<string, list<array{label: string, target: string, permission: string, hint: string, action: string}>>
     */
    public static function catalog(): array
    {
        return [
            'Workspace' => [
                ['label' => 'Dashboard', 'target' => 'dashboard', 'permission' => 'dashboard.view', 'hint' => 'Click Dashboard to see project totals you can access.', 'action' => 'Click Dashboard in the left menu.'],
                ['label' => 'Projects', 'target' => 'projects', 'permission' => 'projects.view', 'hint' => 'Click Projects, then open a project to work inside it.', 'action' => 'Click Projects to open your project list.'],
                ['label' => 'Imports', 'target' => 'imports', 'permission' => 'projects.view', 'hint' => 'Click Imports to download templates and upload Excel/CSV.', 'action' => 'Click Imports when you need to load Excel data.'],
                ['label' => 'Vendors', 'target' => 'vendors', 'permission' => 'vendors.view', 'hint' => 'Click Vendors to manage supplier records and ledgers.', 'action' => 'Click Vendors in the left menu.'],
                ['label' => 'Expenses', 'target' => 'expenses', 'permission' => 'expenses.view', 'hint' => 'Click Expenses to record or review spending.', 'action' => 'Click Expenses in the left menu.'],
                ['label' => 'Reports', 'target' => 'reports', 'permission' => 'reports.view', 'hint' => 'Click Reports to open registers and outstanding reports.', 'action' => 'Click Reports in the left menu.'],
            ],
            'Inside a project' => [
                ['label' => 'Project Dashboard', 'target' => 'project-dashboard', 'permission' => 'projects.view', 'hint' => 'After you open a project, click Project Dashboard for that site.', 'action' => 'Open a project, then click Project Dashboard.'],
                ['label' => 'BOQ', 'target' => 'boq', 'permission' => 'boq.view', 'hint' => 'Inside a project, click BOQ to view items and revisions.', 'action' => 'Open a project, then click BOQ.'],
                ['label' => 'Purchase Requests', 'target' => 'purchase-requests', 'permission' => 'purchase_requests.view', 'hint' => 'Inside a project, click Purchase Requests to create a PR.', 'action' => 'Open a project, then click Purchase Requests.'],
                ['label' => 'Purchase Orders', 'target' => 'purchase-orders', 'permission' => 'purchase_orders.view', 'hint' => 'Inside a project, click Purchase Orders to view vendor orders.', 'action' => 'Open a project, then click Purchase Orders.'],
                ['label' => 'Work Orders', 'target' => 'work-orders', 'permission' => 'work_orders.view', 'hint' => 'Inside a project, click Work Orders for contractor work.', 'action' => 'Open a project, then click Work Orders.'],
                ['label' => 'Store', 'target' => 'store', 'permission' => 'store.view', 'hint' => 'Inside a project, click Store for receipts.', 'action' => 'Open a project, then click Store.'],
                ['label' => 'Purchase Invoices', 'target' => 'invoices', 'permission' => 'invoices.view', 'hint' => 'Inside a project, click Purchase Invoices to record vendor bills.', 'action' => 'Open a project, then click Purchase Invoices.'],
                ['label' => 'Debit Notes', 'target' => 'debit-notes', 'permission' => 'debit_notes.view', 'hint' => 'Inside a project, click Debit Notes.', 'action' => 'Open a project, then click Debit Notes.'],
                ['label' => 'Credit Notes', 'target' => 'credit-notes', 'permission' => 'credit_notes.view', 'hint' => 'Inside a project, click Credit Notes.', 'action' => 'Open a project, then click Credit Notes.'],
                ['label' => 'Project Expenses', 'target' => 'project-expenses', 'permission' => 'expenses.view', 'hint' => 'Inside a project, click Project Expenses.', 'action' => 'Open a project, then click Project Expenses.'],
                ['label' => 'Inventory', 'target' => 'inventory', 'permission' => 'inventory.view', 'hint' => 'Inside a project, click Inventory for materials and stock.', 'action' => 'Open a project, then click Inventory Dashboard.'],
                ['label' => 'Goods Receipt Notes', 'target' => 'grn', 'permission' => 'grn.view', 'hint' => 'Inside a project, click Goods Receipt Notes to receive material.', 'action' => 'Open a project, then click Goods Receipt Notes.'],
                ['label' => 'Internal Delivery Notes', 'target' => 'idn', 'permission' => 'idn.view', 'hint' => 'Inside a project, click Internal Delivery Notes to transfer stock.', 'action' => 'Open a project, then click Internal Delivery Notes.'],
            ],
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return list<array{group: string, items: list<array{label: string, target: ?string, hint: string, action: string}>}>
     */
    public static function forPermissions(array $permissions): array
    {
        $granted = array_fill_keys($permissions, true);
        $groups = [];

        foreach (self::catalog() as $group => $items) {
            $visible = [];
            $seen = [];

            foreach ($items as $item) {
                if (! isset($granted[$item['permission']])) {
                    continue;
                }
                if (isset($seen[$item['label']])) {
                    continue;
                }
                $seen[$item['label']] = true;
                $visible[] = [
                    'label' => $item['label'],
                    'target' => $item['target'] ?? null,
                    'hint' => $item['hint'],
                    'action' => $item['action'] ?? $item['hint'],
                ];
            }

            if ($visible !== []) {
                $groups[] = [
                    'group' => $group,
                    'items' => $visible,
                ];
            }
        }

        return $groups;
    }
}
