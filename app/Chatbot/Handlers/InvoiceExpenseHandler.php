<?php

namespace App\Chatbot\Handlers;

use App\Chatbot\Contracts\IntentHandler;
use App\Chatbot\EntityResolver;
use App\Chatbot\Support\ChatResponse;
use App\Chatbot\Support\ConversationContext;
use App\Chatbot\Support\ListItem;
use App\Chatbot\Support\MoneyFormat;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Expense;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Access;
use App\Services\VendorBalanceService;
use App\Support\DocumentStatus;
use App\Support\Money;

class InvoiceExpenseHandler implements IntentHandler
{
    public function __construct(
        private Access $access,
        private VendorBalanceService $balances,
        private EntityResolver $entities,
    ) {}

    public function handle(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $intent = (string) ($params['_intent'] ?? 'invoices.outstanding');

        return match ($intent) {
            'invoices.find' => $this->findInvoice($user, $params),
            'expenses.summary' => $this->expenses($user, $params, $context),
            'notes.list' => $this->notes($user, $context),
            default => $this->outstandingInvoices($user, $params, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function outstandingInvoices(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $vendorId = $params['vendor_id'] ?? $context->vendorId();
        $projectId = $params['project_id'] ?? $context->projectId();
        $limit = $this->extractLimit((string) ($params['_raw'] ?? ''), 8);

        $query = PurchaseInvoice::query()
            ->with(['vendor:id,name', 'project:id,project_name'])
            ->whereHas('project', fn ($q) => $q->visibleTo($user))
            ->whereIn('status', [PurchaseInvoice::STATUS_RECORDED, PurchaseInvoice::STATUS_PARTIAL])
            ->latest('invoice_date');

        if ($vendorId) {
            $query->where('vendor_id', $vendorId);
        }
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $invoices = $query->limit(50)->get();
        $rows = [];
        $total = '0.00';

        foreach ($invoices as $invoice) {
            $outstanding = $this->balances->invoiceOutstanding($invoice);
            if ((float) $outstanding <= 0) {
                continue;
            }
            $total = Money::add($total, $outstanding);
            $rows[] = [
                'invoice' => $invoice,
                'outstanding' => $outstanding,
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            return new ChatResponse(
                message: 'No outstanding purchase invoices matched your filters.',
                links: [['label' => 'Invoice outstanding report', 'url' => '/reports/invoice-outstanding']],
                contextPatch: ['last_intent' => 'invoices.outstanding', 'vendor_id' => $vendorId, 'project_id' => $projectId],
                intent: 'invoices.outstanding',
            );
        }

        $items = collect($rows)->map(function ($row) {
            /** @var PurchaseInvoice $invoice */
            $invoice = $row['invoice'];

            return ListItem::make(
                title: $invoice->invoice_number,
                subtitle: $invoice->vendor?->name ?? 'Vendor',
                amount: MoneyFormat::inr($row['outstanding']),
                date: $invoice->invoice_date,
                meta: ucfirst((string) $invoice->status),
                url: '/projects/'.$invoice->project_id.'/purchase-invoices/'.$invoice->id,
            );
        })->all();

        $scope = $vendorId ? ' for the selected vendor' : '';

        return new ChatResponse(
            message: 'There are outstanding invoices'.$scope.'. Showing **'.count($rows).'** with combined outstanding **'.MoneyFormat::inr($total).'**.',
            links: [['label' => 'Invoice outstanding report', 'url' => '/reports/invoice-outstanding']],
            suggestions: ['Only for a vendor', 'What is our total payable?'],
            items: $items,
            contextPatch: [
                'last_intent' => 'invoices.outstanding',
                'vendor_id' => $vendorId,
                'project_id' => $projectId,
            ],
            intent: 'invoices.outstanding',
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function findInvoice(User $user, array $params): ChatResponse
    {
        $number = $params['document_number'] ?? null;
        if (! $number) {
            return new ChatResponse(
                message: 'Include the invoice number, for example “Find invoice INV-2026-001”.',
                confidence: 'medium',
                intent: 'invoices.find',
            );
        }

        $invoice = $this->entities->findInvoice(strtoupper((string) $number), $user);
        if (! $invoice) {
            // try loose match
            $invoice = PurchaseInvoice::query()
                ->with('project')
                ->where('invoice_number', 'like', '%'.strtoupper((string) $number).'%')
                ->whereHas('project', fn ($q) => $q->visibleTo($user))
                ->first();
        }

        if (! $invoice || ! $this->access->canAccessProject($user, $invoice->project)) {
            return new ChatResponse(message: 'I could not find that invoice in records you can access.', intent: 'invoices.find');
        }

        $outstanding = $this->balances->invoiceOutstanding($invoice);

        return new ChatResponse(
            message: "Invoice **{$invoice->invoice_number}** dated {$invoice->invoice_date?->toDateString()} — status **{$invoice->status}**, total "
                .MoneyFormat::inr($invoice->total_amount).', outstanding '.MoneyFormat::inr($outstanding).'.',
            links: [['label' => 'Open invoice', 'url' => '/projects/'.$invoice->project_id.'/purchase-invoices/'.$invoice->id]],
            contextPatch: [
                'last_intent' => 'invoices.find',
                'project_id' => $invoice->project_id,
                'vendor_id' => $invoice->vendor_id,
            ],
            intent: 'invoices.find',
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function expenses(User $user, array $params, ConversationContext $context): ChatResponse
    {
        $query = Expense::query()
            ->with(['project:id,project_name', 'category:id,name'])
            ->where('status', DocumentStatus::APPROVED)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        if ($context->projectId() || ! empty($params['project_id'])) {
            $query->where('project_id', $params['project_id'] ?? $context->projectId());
        } else {
            $query->where(function ($q) use ($user) {
                $q->whereNull('project_id')
                    ->orWhereHas('project', fn ($p) => $p->visibleTo($user));
            });
        }

        $expenses = $query->latest('date')->limit(20)->get();
        $total = '0.00';
        foreach ($expenses as $expense) {
            $total = Money::add($total, (string) $expense->total_amount);
        }

        if ($expenses->isEmpty()) {
            return new ChatResponse(
                message: 'No **approved** expenses were found for this month under your access.',
                links: [['label' => 'Expense summary report', 'url' => '/reports/expense-summary']],
                intent: 'expenses.summary',
            );
        }

        $lines = $expenses->take(5)->map(function (Expense $expense) {
            return '• '.$expense->number.' — '.($expense->category?->name ?? 'Category').' — '.MoneyFormat::inr($expense->total_amount);
        })->implode("\n");

        return new ChatResponse(
            message: 'This month’s **approved** expenses total **'.MoneyFormat::inr($total)."** ({$expenses->count()} shown in query).\n{$lines}",
            links: [
                ['label' => 'Expenses', 'url' => '/expenses'],
                ['label' => 'Expense summary report', 'url' => '/reports/expense-summary'],
            ],
            contextPatch: ['last_intent' => 'expenses.summary'],
            intent: 'expenses.summary',
        );
    }

    private function notes(User $user, ConversationContext $context): ChatResponse
    {
        $debitQuery = DebitNote::query()->with('vendor:id,name')->latest('date');
        $creditQuery = CreditNote::query()->with('vendor:id,name')->latest('date');

        if ($context->projectId()) {
            $debitQuery->where('project_id', $context->projectId());
            $creditQuery->where('project_id', $context->projectId());
        } else {
            $debitQuery->whereHas('project', fn ($q) => $q->visibleTo($user));
            $creditQuery->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        if ($context->vendorId()) {
            $debitQuery->where('vendor_id', $context->vendorId());
            $creditQuery->where('vendor_id', $context->vendorId());
        }

        $debits = $debitQuery->limit(5)->get();
        $credits = $creditQuery->limit(5)->get();

        $lines = [];
        foreach ($debits as $note) {
            $lines[] = '• DN '.$note->number.' — '.$note->status.' — '.MoneyFormat::inr($note->total_amount);
        }
        foreach ($credits as $note) {
            $lines[] = '• CN '.$note->number.' — '.$note->status.' — '.MoneyFormat::inr($note->total_amount);
        }

        if ($lines === []) {
            return new ChatResponse(message: 'No debit or credit notes matched your filters.', intent: 'notes.list');
        }

        return new ChatResponse(
            message: "Recent debit/credit notes (drafts do not affect payable until approved):\n".implode("\n", $lines),
            links: [['label' => 'DN/CN register report', 'url' => '/reports/debit-credit-note-register']],
            contextPatch: ['last_intent' => 'notes.list'],
            intent: 'notes.list',
        );
    }

    private function extractLimit(string $message, int $default): int
    {
        if (preg_match('/\b(last|top|first)\s+(\d{1,2})\b/i', $message, $m)) {
            return max(1, min(20, (int) $m[2]));
        }

        return $default;
    }
}
