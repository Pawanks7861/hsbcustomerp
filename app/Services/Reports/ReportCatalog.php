<?php

namespace App\Services\Reports;

class ReportCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::entry('project-summary', 'Project summary', 'Projects & BOQ', ['from', 'to'], [
                ['key' => 'project_code', 'label' => 'Code'],
                ['key' => 'project_name', 'label' => 'Project'],
                ['key' => 'boq_total', 'label' => 'BOQ total', 'align' => 'right', 'money' => true],
                ['key' => 'po_total', 'label' => 'PO total (approved)', 'align' => 'right', 'money' => true],
                ['key' => 'invoice_total', 'label' => 'Invoice total', 'align' => 'right', 'money' => true],
                ['key' => 'expense_total', 'label' => 'Expenses (approved)', 'align' => 'right', 'money' => true],
                ['key' => 'wo_total', 'label' => 'Work orders', 'align' => 'right', 'money' => true],
            ]),
            self::entry('procurement-summary', 'Procurement summary', 'Procurement', ['project', 'from', 'to'], [
                ['key' => 'label', 'label' => 'Metric'],
                ['key' => 'value', 'label' => 'Value', 'align' => 'right'],
            ], metric: true),
            self::entry('boq-quantity-consumption', 'BOQ quantity consumption', 'Projects & BOQ', ['project'], [
                ['key' => 'item_number', 'label' => 'Item'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'boq_quantity', 'label' => 'BOQ qty', 'align' => 'right'],
                ['key' => 'requested_quantity', 'label' => 'Requested', 'align' => 'right'],
                ['key' => 'ordered_quantity', 'label' => 'Ordered', 'align' => 'right'],
                ['key' => 'received_quantity', 'label' => 'Received', 'align' => 'right'],
                ['key' => 'invoiced_quantity', 'label' => 'Invoiced', 'align' => 'right'],
            ]),
            self::entry('purchase-order-register', 'Purchase order register', 'Procurement', ['project', 'vendor', 'from', 'to', 'status'], [
                ['key' => 'po_number', 'label' => 'PO #'],
                ['key' => 'po_date', 'label' => 'Date'],
                ['key' => 'project_code', 'label' => 'Project'],
                ['key' => 'vendor_name', 'label' => 'Vendor'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'invoiced_total', 'label' => 'Invoiced', 'align' => 'right', 'money' => true],
                ['key' => 'remaining_billable', 'label' => 'Remaining billable', 'align' => 'right', 'money' => true],
            ]),
            self::entry('invoice-outstanding', 'Invoice outstanding', 'Purchase invoices', ['project', 'vendor', 'from', 'to'], [
                ['key' => 'invoice_number', 'label' => 'Invoice #'],
                ['key' => 'invoice_date', 'label' => 'Date'],
                ['key' => 'project_code', 'label' => 'Project'],
                ['key' => 'vendor_name', 'label' => 'Vendor'],
                ['key' => 'total_amount', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'paid_amount', 'label' => 'Paid', 'align' => 'right', 'money' => true],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right', 'money' => true],
                ['key' => 'status', 'label' => 'Status'],
            ]),
            self::entry('expense-summary', 'Expense summary', 'Expenses', ['project', 'from', 'to'], [], sections: true),
            self::entry('vendor-outstanding', 'Vendor outstanding', 'Vendors', ['project', 'vendor', 'from', 'to'], [
                ['key' => 'vendor_code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Vendor'],
                ['key' => 'total_invoice_value', 'label' => 'Invoices', 'align' => 'right', 'money' => true],
                ['key' => 'invoice_payments', 'label' => 'Paid', 'align' => 'right', 'money' => true],
                ['key' => 'outstanding_payable', 'label' => 'Outstanding', 'align' => 'right', 'money' => true],
                ['key' => 'available_advance', 'label' => 'Advance', 'align' => 'right', 'money' => true],
            ]),
            self::entry('debit-credit-note-register', 'Debit & credit note register', 'Debit & credit notes', ['project', 'vendor', 'from', 'to', 'status'], [
                ['key' => 'note_type', 'label' => 'Type'],
                ['key' => 'number', 'label' => 'Number'],
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'project_code', 'label' => 'Project'],
                ['key' => 'vendor_name', 'label' => 'Vendor'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'total_amount', 'label' => 'Amount', 'align' => 'right', 'money' => true],
            ]),
            self::entry('client-invoice-register', 'Client invoice register', 'Client invoices', ['project', 'client', 'from', 'to', 'status'], [
                ['key' => 'invoice_number', 'label' => 'Invoice #'],
                ['key' => 'invoice_date', 'label' => 'Date'],
                ['key' => 'project_code', 'label' => 'Project'],
                ['key' => 'client_name', 'label' => 'Client'],
                ['key' => 'approval_status', 'label' => 'Approval'],
                ['key' => 'payment_status', 'label' => 'Payment'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'paid_amount', 'label' => 'Received', 'align' => 'right', 'money' => true],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right', 'money' => true],
            ]),
            self::entry('client-outstanding', 'Outstanding receivables', 'Client invoices', ['project', 'client', 'from', 'to'], [
                ['key' => 'invoice_number', 'label' => 'Invoice #'],
                ['key' => 'invoice_date', 'label' => 'Date'],
                ['key' => 'due_date', 'label' => 'Due'],
                ['key' => 'project_code', 'label' => 'Project'],
                ['key' => 'client_name', 'label' => 'Client'],
                ['key' => 'grand_total', 'label' => 'Total', 'align' => 'right', 'money' => true],
                ['key' => 'paid_amount', 'label' => 'Received', 'align' => 'right', 'money' => true],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right', 'money' => true],
                ['key' => 'overdue', 'label' => 'Overdue'],
            ]),
            self::entry('client-payment-register', 'Client payment register', 'Client invoices', ['project', 'client', 'from', 'to'], [
                ['key' => 'payment_date', 'label' => 'Date'],
                ['key' => 'invoice_number', 'label' => 'Invoice #'],
                ['key' => 'project_code', 'label' => 'Project'],
                ['key' => 'client_name', 'label' => 'Client'],
                ['key' => 'payment_mode', 'label' => 'Mode'],
                ['key' => 'reference_number', 'label' => 'Reference'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'money' => true],
            ]),
            self::entry('stock-balance', 'Stock balance', 'Store & inventory', ['project'], [
                ['key' => 'material_code', 'label' => 'Code'],
                ['key' => 'material_name', 'label' => 'Material'],
                ['key' => 'classification', 'label' => 'Class'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'total_received', 'label' => 'Received', 'align' => 'right'],
                ['key' => 'total_issued', 'label' => 'Issued', 'align' => 'right'],
                ['key' => 'available', 'label' => 'On hand', 'align' => 'right'],
                ['key' => 'unit_cost', 'label' => 'Valuation unit cost', 'align' => 'right', 'money' => true],
                ['key' => 'stock_value', 'label' => 'Stock value', 'align' => 'right', 'money' => true],
                ['key' => 'cost_basis', 'label' => 'Cost basis'],
                ['key' => 'status', 'label' => 'Status'],
            ]),
            self::entry('stock-valuation', 'Stock valuation', 'Store & inventory', ['project'], [
                ['key' => 'store', 'label' => 'Store'],
                ['key' => 'material_code', 'label' => 'Code'],
                ['key' => 'material_name', 'label' => 'Material'],
                ['key' => 'classification', 'label' => 'Class'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'available', 'label' => 'On hand', 'align' => 'right'],
                ['key' => 'unit_cost', 'label' => 'Valuation unit cost', 'align' => 'right', 'money' => true],
                ['key' => 'stock_value', 'label' => 'Stock value', 'align' => 'right', 'money' => true],
                ['key' => 'cost_basis', 'label' => 'Cost basis'],
            ]),
            self::entry('project-material-consumption', 'Project material consumption', 'Store & inventory', ['project'], [
                ['key' => 'material_code', 'label' => 'Code'],
                ['key' => 'material_name', 'label' => 'Material'],
                ['key' => 'classification', 'label' => 'Class'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'quantity', 'label' => 'Qty issued', 'align' => 'right'],
                ['key' => 'unit_cost', 'label' => 'Unit cost', 'align' => 'right', 'money' => true],
                ['key' => 'amount', 'label' => 'Consumption cost', 'align' => 'right', 'money' => true],
                ['key' => 'cost_basis', 'label' => 'Cost basis'],
            ]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $report) {
            if ($report['id'] === $key) {
                return $report;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $filters
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private static function entry(
        string $id,
        string $title,
        string $group,
        array $filters,
        array $columns,
        bool $metric = false,
        bool $sections = false,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'group' => $group,
            'route' => route('reports.show', ['report' => $id]),
            'filters' => $filters,
            'columns' => $columns,
            'metric' => $metric,
            'sections' => $sections,
        ];
    }
}
