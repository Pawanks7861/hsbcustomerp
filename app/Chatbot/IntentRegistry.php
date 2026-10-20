<?php

namespace App\Chatbot;

use App\Chatbot\Handlers\BoqHandler;
use App\Chatbot\Handlers\HelpHandler;
use App\Chatbot\Handlers\InventoryHandler;
use App\Chatbot\Handlers\InvoiceExpenseHandler;
use App\Chatbot\Handlers\ProcurementHandler;
use App\Chatbot\Handlers\ProjectHandler;
use App\Chatbot\Handlers\ReportHandler;
use App\Chatbot\Handlers\VendorHandler;
use App\Chatbot\Handlers\WorkOrderHandler;
use InvalidArgumentException;

class IntentRegistry
{
    /** @var list<IntentDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return list<IntentDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $id): IntentDefinition
    {
        foreach ($this->all() as $intent) {
            if ($intent->id === $id) {
                return $intent;
            }
        }

        throw new InvalidArgumentException("Unknown intent [{$id}].");
    }

    /**
     * @return list<IntentDefinition>
     */
    private function build(): array
    {
        return [
            new IntentDefinition(
                id: 'help.general',
                name: 'ERP help',
                phrases: ['how do i', 'how can i', 'how to', 'where can i', 'help me'],
                keywords: ['how', 'where', 'help', 'create', 'import', 'download'],
                synonyms: ['guide', 'steps', 'instruction'],
                permissions: [],
                handler: HelpHandler::class,
                suggestions: ['How do I create a purchase order?', 'How do I import Excel?', 'Show pending PRs'],
                priority: 40,
            ),
            new IntentDefinition(
                id: 'projects.count',
                name: 'Project count',
                phrases: ['how many projects', 'project count', 'number of projects'],
                keywords: ['how many', 'projects', 'count'],
                synonyms: ['total projects'],
                permissions: ['projects.view'],
                handler: ProjectHandler::class,
                suggestions: ['Show my projects', 'Find project'],
            ),
            new IntentDefinition(
                id: 'projects.list',
                name: 'List projects',
                phrases: ['show my projects', 'list projects', 'show projects'],
                keywords: ['show', 'list', 'projects'],
                synonyms: ['my projects'],
                permissions: ['projects.view'],
                handler: ProjectHandler::class,
                suggestions: ['What is the BOQ amount?', 'Find project'],
            ),
            new IntentDefinition(
                id: 'projects.find',
                name: 'Find project',
                phrases: ['find project', 'show project details', 'open project'],
                keywords: ['find', 'project', 'details'],
                synonyms: ['search project'],
                permissions: ['projects.view'],
                handler: ProjectHandler::class,
                requiredParams: ['project'],
                suggestions: ['What is its BOQ amount?', 'Show pending PRs'],
            ),
            new IntentDefinition(
                id: 'boq.total',
                name: 'BOQ total',
                phrases: ['what is the total boq amount', 'boq value', 'boq amount', 'current project budget', 'boq total'],
                keywords: ['boq', 'amount', 'total', 'budget', 'value'],
                synonyms: ['bill of quantities'],
                permissions: ['boq.view'],
                handler: BoqHandler::class,
                suggestions: ['Show BOQ items', 'Open BOQ quantity consumption report'],
            ),
            new IntentDefinition(
                id: 'procurement.pending_prs',
                name: 'Pending purchase requests',
                phrases: ['show pending purchase requisitions', 'pending prs', 'which prs are waiting for approval', 'prs waiting for approval'],
                keywords: ['pending', 'purchase', 'requisition', 'approval', 'pr'],
                synonyms: ['waiting approval', 'submitted pr'],
                permissions: ['purchase_requests.view'],
                handler: ProcurementHandler::class,
                suggestions: ['Show approved purchase orders', 'Find PO'],
            ),
            new IntentDefinition(
                id: 'procurement.approved_pos',
                name: 'Approved purchase orders',
                phrases: ['show approved purchase orders', 'approved pos', 'recent purchase orders'],
                keywords: ['approved', 'purchase', 'order', 'po'],
                synonyms: ['recent po'],
                permissions: ['purchase_orders.view'],
                handler: ProcurementHandler::class,
                suggestions: ['Which orders are awaiting receipt?', 'Find PO'],
            ),
            new IntentDefinition(
                id: 'procurement.find_po',
                name: 'Find purchase order',
                phrases: ['find po', 'show purchase order', 'open po'],
                keywords: ['find', 'po', 'purchase order'],
                synonyms: ['locate po'],
                permissions: ['purchase_orders.view'],
                handler: ProcurementHandler::class,
                requiredParams: ['document_number'],
            ),
            new IntentDefinition(
                id: 'procurement.awaiting_receipt',
                name: 'Orders awaiting receipt',
                phrases: ['which orders are awaiting receipt', 'awaiting receipt', 'pending grn'],
                keywords: ['awaiting', 'receipt', 'grn', 'orders'],
                synonyms: ['not received'],
                permissions: ['purchase_orders.view'],
                handler: ProcurementHandler::class,
            ),
            new IntentDefinition(
                id: 'inventory.stock',
                name: 'Stock balance',
                phrases: ['what is the current stock', 'show stock', 'stock balance', 'stock for item'],
                keywords: ['stock', 'inventory', 'on hand', 'balance'],
                synonyms: ['material stock'],
                permissions: ['inventory.view'],
                handler: InventoryHandler::class,
                suggestions: ['Which items have low stock?', 'Show recent GRNs'],
            ),
            new IntentDefinition(
                id: 'inventory.low_stock',
                name: 'Low stock items',
                phrases: ['which items have low stock', 'low stock', 'below minimum'],
                keywords: ['low', 'stock', 'minimum'],
                synonyms: ['shortage'],
                permissions: ['inventory.view'],
                handler: InventoryHandler::class,
            ),
            new IntentDefinition(
                id: 'inventory.recent_grns',
                name: 'Recent GRNs',
                phrases: ['show recent grns', 'recent goods receipts', 'latest grn'],
                keywords: ['grn', 'goods', 'receipt', 'recent'],
                synonyms: ['material receipt'],
                permissions: ['grn.view'],
                handler: InventoryHandler::class,
            ),
            new IntentDefinition(
                id: 'vendors.total_payable',
                name: 'Total outstanding payable',
                phrases: [
                    'how much money is outstanding with vendors',
                    'what is our total payable',
                    'total outstanding payable',
                    'how much do we owe vendors',
                    'vendor outstanding',
                ],
                keywords: ['outstanding', 'payable', 'owe', 'vendors', 'total'],
                synonyms: ['payables', 'dues', 'liability'],
                permissions: ['vendors.ledger', 'vendors.view'],
                handler: VendorHandler::class,
                suggestions: ['How much do we owe a vendor?', 'Show outstanding invoices', 'Open vendor outstanding report'],
                priority: 20,
            ),
            new IntentDefinition(
                id: 'vendors.vendor_balance',
                name: 'Vendor balance',
                phrases: [
                    'how much do we owe',
                    'show me pending vendor payments',
                    'which vendors do we still have to pay',
                    'vendor payment history',
                    'how much advance',
                    'show vendor ledger',
                ],
                keywords: ['owe', 'vendor', 'payable', 'advance', 'ledger', 'payment'],
                synonyms: ['balance', 'due'],
                permissions: ['vendors.ledger', 'vendors.view'],
                handler: VendorHandler::class,
                suggestions: ['Show outstanding invoices', 'Open vendor ledger'],
                priority: 30,
            ),
            new IntentDefinition(
                id: 'invoices.outstanding',
                name: 'Outstanding invoices',
                phrases: [
                    'which invoices are outstanding',
                    'show unpaid purchase invoices',
                    'show outstanding invoices',
                    'show overdue invoices',
                ],
                keywords: ['invoice', 'outstanding', 'unpaid', 'overdue'],
                synonyms: ['due invoices'],
                permissions: ['invoices.view'],
                handler: InvoiceExpenseHandler::class,
                suggestions: ['Only for a vendor', 'Open invoice outstanding report'],
            ),
            new IntentDefinition(
                id: 'invoices.find',
                name: 'Find invoice',
                phrases: ['find invoice', 'show invoice'],
                keywords: ['find', 'invoice'],
                synonyms: [],
                permissions: ['invoices.view'],
                handler: InvoiceExpenseHandler::class,
                requiredParams: ['document_number'],
            ),
            new IntentDefinition(
                id: 'expenses.summary',
                name: 'Expenses',
                phrases: ['what are this months expenses', 'this month expenses', 'show expenses', 'expense summary'],
                keywords: ['expense', 'expenses', 'spending'],
                synonyms: ['costs'],
                permissions: ['expenses.view'],
                handler: InvoiceExpenseHandler::class,
                suggestions: ['Open expense summary report'],
            ),
            new IntentDefinition(
                id: 'notes.list',
                name: 'Debit and credit notes',
                phrases: ['show debit notes and credit notes', 'debit notes', 'credit notes', 'refund status'],
                keywords: ['debit', 'credit', 'note', 'refund'],
                synonyms: ['dn', 'cn'],
                permissions: ['debit_notes.view', 'credit_notes.view'],
                handler: InvoiceExpenseHandler::class,
            ),
            new IntentDefinition(
                id: 'work_orders.list',
                name: 'Work orders',
                phrases: ['show work orders', 'work orders for this project', 'pending work', 'find work order'],
                keywords: ['work order', 'contractor', 'wo'],
                synonyms: ['works'],
                permissions: ['work_orders.view'],
                handler: WorkOrderHandler::class,
            ),
            new IntentDefinition(
                id: 'reports.open',
                name: 'Open report',
                phrases: [
                    'show the purchase order register',
                    'show the invoice outstanding report',
                    'give me the vendor outstanding report',
                    'show stock balance',
                    'show expense summary',
                    'open the boq quantity consumption report',
                    'open report',
                ],
                keywords: ['report', 'register', 'summary', 'consumption'],
                synonyms: ['analytics'],
                permissions: ['reports.view'],
                handler: ReportHandler::class,
            ),
        ];
    }
}
