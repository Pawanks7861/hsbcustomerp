<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Support\Collection;

class ClientLedgerService
{
    /**
     * @param  list<int>|null  $projectIds
     * @return array{entries: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function statement(Client $client, ?int $projectId = null, ?array $projectIds = null): array
    {
        $invoices = $client->invoices()
            ->with('project:id,project_code,project_name')
            ->where('approval_status', DocumentStatus::APPROVED)
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when($projectId === null && $projectIds !== null, fn ($query) => $query->whereIn('project_id', $projectIds ?: [0]))
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $payments = $client->payments()
            ->with(['invoice:id,invoice_number', 'project:id,project_code'])
            ->whereHas('invoice', fn ($query) => $query->where('approval_status', DocumentStatus::APPROVED))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when($projectId === null && $projectIds !== null, fn ($query) => $query->whereIn('project_id', $projectIds ?: [0]))
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $entries = collect();
        foreach ($invoices as $invoice) {
            $entries->push([
                'date' => $invoice->invoice_date?->toDateString(),
                'sort' => $invoice->invoice_date?->format('Y-m-d').'-I-'.$invoice->id,
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'project_code' => $invoice->project?->project_code,
                'particulars' => 'Client invoice '.$invoice->invoice_number,
                'debit' => Money::scale((string) $invoice->grand_total, 2) ?? '0.00',
                'credit' => '0.00',
            ]);
        }
        foreach ($payments as $payment) {
            $entries->push([
                'date' => $payment->payment_date?->toDateString(),
                'sort' => $payment->payment_date?->format('Y-m-d').'-P-'.$payment->id,
                'type' => 'payment',
                'reference' => $payment->reference_number ?: $payment->invoice?->invoice_number,
                'project_code' => $payment->project?->project_code,
                'particulars' => 'Receipt against '.($payment->invoice?->invoice_number ?? 'invoice'),
                'debit' => '0.00',
                'credit' => Money::scale((string) $payment->amount, 2) ?? '0.00',
            ]);
        }

        $running = '0.00';
        $debit = '0.00';
        $credit = '0.00';
        $rows = $entries->sortBy('sort')->values()->map(function (array $entry) use (&$running, &$debit, &$credit) {
            $running = Money::add($running, Money::sub($entry['debit'], $entry['credit']));
            $debit = Money::add($debit, $entry['debit']);
            $credit = Money::add($credit, $entry['credit']);
            $entry['balance'] = $running;
            unset($entry['sort']);

            return $entry;
        })->all();

        return [
            'entries' => $rows,
            'totals' => [
                'invoiced' => $debit,
                'received' => $credit,
                'outstanding' => $running,
            ],
        ];
    }

    /**
     * @param  list<int>|null  $projectIds
     * @return array<string, string>
     */
    public function summary(Client $client, ?array $projectIds = null): array
    {
        $statement = $this->statement($client, null, $projectIds);

        return $statement['totals'];
    }

    public function invoiceOutstanding(ClientInvoice $invoice): string
    {
        $invoice->loadMissing('payments');

        return $invoice->isApproved() ? $invoice->outstanding() : '0.00';
    }

    /**
     * @param  Collection<int, ClientInvoice>  $invoices
     */
    public function refreshMany(Collection $invoices): void
    {
        foreach ($invoices as $invoice) {
            app(ClientInvoiceService::class)->refreshPaymentStatus($invoice->loadMissing('payments'));
        }
    }
}
