<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\Vendor;
use App\Models\VendorAdvanceAdjustment;
use App\Models\VendorPayment;
use App\Models\VendorRecovery;
use App\Models\VendorRefund;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Single vendor payable ledger (chronological statement).
 *
 * - Credit: purchase invoices (+ vendor opening_outstanding in opening balance when unscoped).
 * - Debit: invoice payments, other payments, advance adjustments, approved debit notes, approved credit notes, refunds received.
 * - Advance payments are not ledger debits until adjusted against invoices.
 * - Refund due (recovery): credit on the statement (amount to recover); separate from outstanding_payable in balance summary.
 * - Draft and rejected notes: excluded (approved only).
 *
 * Both vendor debit notes and credit notes received reduce payable (debit on this ledger), matching buyer-side AP reduction.
 */
class VendorLedgerService
{
    public function __construct(private VendorBalanceService $balances) {}

    /**
     * @return array{
     *     ledger_opening: string,
     *     ledger_credits: string,
     *     ledger_debits: string,
     *     ledger_closing: string,
     *     payable_basis_opening: string,
     *     payable_basis_credits: string,
     *     payable_basis_debits: string,
     *     payable_basis_closing: string,
     *     outstanding_payable: string,
     *     advance_paid: string,
     *     advance_adjusted: string,
     *     available_advance: string,
     *     refund_due: string,
     *     payable_components: array{invoice_credits: string, payment_debits: string, adjustment_debits: string, notes_debits: string},
     *     expected_payable: string,
     *     difference: string,
     *     payable_basis_difference: string,
     *     reconciled: bool
     * }
     */
    /**
     * @param  list<int>|null  $projectIds
     */
    public function reconcile(Vendor $vendor, ?int $projectId = null, ?string $from = null, ?string $to = null, ?array $projectIds = null): array
    {
        $statement = $this->statement($vendor, $projectId, $from, $to, $projectIds);
        $summary = $this->balances->summary($vendor, $projectId, $from, $to, $projectIds);
        $components = $this->balances->payableComponents($vendor, $projectId, $from, $to, $projectIds);

        $payableOpening = $this->payableBasisOpening($vendor, $projectId, $from, $projectIds);
        $payableCredits = '0.00';
        $payableDebits = '0.00';
        $payableClosing = $payableOpening;

        foreach ($statement['entries'] as $entry) {
            if ($this->excludedFromPayableBasis((string) $entry['type'])) {
                continue;
            }

            $debit = (string) ($entry['debit'] ?? '0.00');
            $credit = (string) ($entry['credit'] ?? '0.00');
            if ($debit !== '0.00' && $debit !== '') {
                $payableDebits = Money::add($payableDebits, $debit);
            }
            if ($credit !== '0.00' && $credit !== '') {
                $payableCredits = Money::add($payableCredits, $credit);
            }
            $payableClosing = Money::add(Money::sub($payableClosing, $debit ?: '0.00'), $credit ?: '0.00');
        }

        $expectedPayable = Money::sub(
            Money::sub(
                Money::sub(
                    Money::add($payableOpening, $components['invoice_credits']),
                    $components['payment_debits']
                ),
                $components['adjustment_debits']
            ),
            $components['notes_debits']
        );

        $outstanding = $summary['outstanding_payable'];
        $difference = Money::sub($expectedPayable, $outstanding);
        $payableBasisClosing = Money::scale($payableClosing, 2) ?? '0.00';
        $payableBasisDifference = Money::sub($payableBasisClosing, $outstanding);
        $tolerance = '0.009';

        $reconciled = $this->withinTolerance($difference, $tolerance)
            && $this->withinTolerance($payableBasisDifference, $tolerance);

        return [
            'ledger_opening' => $statement['opening'],
            'ledger_credits' => $statement['total_credit'],
            'ledger_debits' => $statement['total_debit'],
            'ledger_closing' => $statement['closing'],
            'payable_basis_opening' => $payableOpening,
            'payable_basis_credits' => Money::scale($payableCredits, 2) ?? '0.00',
            'payable_basis_debits' => Money::scale($payableDebits, 2) ?? '0.00',
            'payable_basis_closing' => $payableBasisClosing,
            'outstanding_payable' => $outstanding,
            'advance_paid' => $summary['advance_paid'],
            'advance_adjusted' => $summary['advance_adjusted'],
            'available_advance' => $summary['available_advance'],
            'refund_due' => $summary['refund_due'],
            'payable_components' => $components,
            'expected_payable' => Money::scale($expectedPayable, 2) ?? '0.00',
            'difference' => Money::scale($difference, 2) ?? '0.00',
            'payable_basis_difference' => Money::scale($payableBasisDifference, 2) ?? '0.00',
            'reconciled' => $reconciled,
        ];
    }

    /**
     * @return array{
     *     opening: string,
     *     closing: string,
     *     total_debit: string,
     *     total_credit: string,
     *     entries: list<array<string, mixed>>
     * }
     */
    /**
     * @param  list<int>|null  $projectIds
     */
    public function statement(Vendor $vendor, ?int $projectId = null, ?string $from = null, ?string $to = null, ?array $projectIds = null): array
    {
        $rows = $this->rawEntries($vendor, $projectId, $from, $to, $projectIds);
        $opening = $this->openingBalance($vendor, $projectId, $from, $projectIds);
        $balance = $opening;
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $entries = [];

        foreach ($rows as $row) {
            $debit = (string) ($row['debit'] ?? '0.00');
            $credit = (string) ($row['credit'] ?? '0.00');
            $totalDebit = Money::add($totalDebit, $debit);
            $totalCredit = Money::add($totalCredit, $credit);
            $balance = Money::add(Money::sub($balance, $debit), $credit);
            $entries[] = [
                ...$row,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => Money::scale($balance, 2) ?? '0.00',
            ];
        }

        return [
            'opening' => $opening,
            'closing' => Money::scale($balance, 2) ?? '0.00',
            'total_debit' => Money::scale($totalDebit, 2) ?? '0.00',
            'total_credit' => Money::scale($totalCredit, 2) ?? '0.00',
            'entries' => $entries,
        ];
    }

    /**
     * @param  list<int>|null  $projectIds
     */
    private function payableBasisOpening(Vendor $vendor, ?int $projectId, ?string $from, ?array $projectIds = null): string
    {
        $filtered = $projectId !== null || $from !== null || $projectIds !== null;
        $balance = '0.00';

        if (! $filtered) {
            $balance = Money::add($balance, (string) $vendor->opening_outstanding);
        }

        if ($from === null) {
            return Money::scale($balance, 2) ?? '0.00';
        }

        $prior = $this->rawEntries($vendor, $projectId, null, date('Y-m-d', strtotime($from.' -1 day')), $projectIds);

        foreach ($prior as $row) {
            if ($this->excludedFromPayableBasis((string) ($row['type'] ?? ''))) {
                continue;
            }

            $balance = Money::add(
                Money::sub($balance, (string) ($row['debit'] ?? '0.00')),
                (string) ($row['credit'] ?? '0.00')
            );
        }

        return Money::scale($balance, 2) ?? '0.00';
    }

    private function excludedFromPayableBasis(string $type): bool
    {
        return in_array($type, ['Refund Due', 'Refund Received'], true);
    }

    private function withinTolerance(string $difference, string $tolerance): bool
    {
        $abs = bccomp($difference, '0', 3) === -1 ? Money::sub('0.00', $difference) : $difference;

        return bccomp($abs, $tolerance, 3) !== 1;
    }

    /**
     * @param  list<int>|null  $projectIds
     */
    private function openingBalance(Vendor $vendor, ?int $projectId, ?string $from, ?array $projectIds = null): string
    {
        $filtered = $projectId !== null || $from !== null || $projectIds !== null;
        $balance = '0.00';

        if (! $filtered) {
            $balance = Money::add($balance, (string) $vendor->opening_outstanding);
        }

        if ($from === null) {
            return Money::scale($balance, 2) ?? '0.00';
        }

        $prior = $this->rawEntries($vendor, $projectId, null, date('Y-m-d', strtotime($from.' -1 day')), $projectIds);

        foreach ($prior as $row) {
            $balance = Money::add(
                Money::sub($balance, (string) ($row['debit'] ?? '0.00')),
                (string) ($row['credit'] ?? '0.00')
            );
        }

        return Money::scale($balance, 2) ?? '0.00';
    }

    /**
     * @param  list<int>|null  $projectIds
     * @return list<array<string, mixed>>
     */
    private function rawEntries(Vendor $vendor, ?int $projectId, ?string $from, ?string $to, ?array $projectIds = null): array
    {
        $rows = [];

        $invoiceQuery = $vendor->invoices()->where('approval_status', PurchaseInvoice::APPROVAL_APPROVED);
        $this->constrainProject($invoiceQuery, 'project_id', $projectId, $projectIds);
        if ($from) {
            $invoiceQuery->whereDate('invoice_date', '>=', $from);
        }
        if ($to) {
            $invoiceQuery->whereDate('invoice_date', '<=', $to);
        }

        foreach ($invoiceQuery->with(['project', 'purchaseOrder'])->orderBy('invoice_date')->orderBy('id')->get() as $invoice) {
            /** @var PurchaseInvoice $invoice */
            $projectHref = $invoice->project_id ? '/projects/'.$invoice->project_id : null;
            $invoiceHref = $invoice->project_id
                ? '/projects/'.$invoice->project_id.'/purchase-invoices/'.$invoice->id
                : null;
            $poHref = $invoice->project_id && $invoice->purchase_order_id
                ? '/projects/'.$invoice->project_id.'/purchase-orders/'.$invoice->purchase_order_id
                : null;
            $rows[] = $this->row(
                $invoice->invoice_date?->toDateString(),
                'Purchase Invoice',
                $invoice->invoice_number,
                $invoice->project?->project_name,
                null,
                (string) $invoice->total_amount,
                $invoice->invoice_date?->format('Ymd').'I'.$invoice->id,
                $invoiceHref,
                $projectHref,
                $invoice->purchaseOrder?->po_number,
                $poHref,
            );
        }

        $paymentQuery = $vendor->payments();
        $this->constrainProject($paymentQuery, 'project_id', $projectId, $projectIds);
        if ($from) {
            $paymentQuery->whereDate('payment_date', '>=', $from);
        }
        if ($to) {
            $paymentQuery->whereDate('payment_date', '<=', $to);
        }

        foreach ($paymentQuery->with(['project', 'invoice'])->orderBy('payment_date')->orderBy('id')->get() as $payment) {
            /** @var VendorPayment $payment */
            // Advance payments fund the vendor separately; only invoice/other payments and adjustments reduce payable.
            $debit = $payment->payment_type === VendorPayment::TYPE_ADVANCE
                ? null
                : (string) $payment->amount;

            $reference = $payment->invoice?->invoice_number ?? $payment->reference_number ?? 'Payment '.$payment->id;
            $href = null;
            if ($payment->purchase_invoice_id && $payment->invoice?->project_id) {
                $href = '/projects/'.$payment->invoice->project_id.'/purchase-invoices/'.$payment->purchase_invoice_id;
            } elseif ($payment->vendor_id) {
                $href = '/vendors/'.$payment->vendor_id;
            }

            $rows[] = $this->row(
                $payment->payment_date?->toDateString(),
                $payment->typeLabel(),
                $reference,
                $payment->project?->project_name,
                $debit,
                null,
                $payment->payment_date?->format('Ymd').'P'.$payment->id,
                $href,
                $payment->project_id ? '/projects/'.$payment->project_id : null,
            );
        }

        $adjustmentQuery = VendorAdvanceAdjustment::query()->where('vendor_id', $vendor->id);
        if ($projectId !== null) {
            $adjustmentQuery->whereHas('invoice', fn ($q) => $q->where('project_id', $projectId));
        } elseif ($projectIds !== null) {
            $adjustmentQuery->whereHas('invoice', fn ($q) => $q->whereIn('project_id', $projectIds ?: [0]));
        }
        if ($from) {
            $adjustmentQuery->whereDate('adjustment_date', '>=', $from);
        }
        if ($to) {
            $adjustmentQuery->whereDate('adjustment_date', '<=', $to);
        }

        foreach ($adjustmentQuery->with('invoice.project')->orderBy('adjustment_date')->orderBy('id')->get() as $adjustment) {
            $invoice = $adjustment->invoice;
            $href = $invoice && $invoice->project_id
                ? '/projects/'.$invoice->project_id.'/purchase-invoices/'.$invoice->id
                : null;
            $rows[] = $this->row(
                $adjustment->adjustment_date?->toDateString(),
                'Advance Adjustment',
                $invoice?->invoice_number,
                $invoice?->project?->project_name,
                (string) $adjustment->amount,
                null,
                $adjustment->adjustment_date?->format('Ymd').'A'.$adjustment->id,
                $href,
                $invoice?->project_id ? '/projects/'.$invoice->project_id : null,
            );
        }

        $recoveryQuery = VendorRecovery::query()->where('vendor_id', $vendor->id);
        $this->constrainProject($recoveryQuery, 'project_id', $projectId, $projectIds);
        if ($from) {
            $recoveryQuery->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $recoveryQuery->whereDate('created_at', '<=', $to);
        }

        foreach ($recoveryQuery->with('project')->orderBy('created_at')->orderBy('id')->get() as $recovery) {
            $rows[] = $this->row(
                $recovery->recovery_date?->toDateString() ?? $recovery->created_at?->toDateString(),
                'Refund Due',
                'Recovery '.$recovery->id,
                $recovery->project?->project_name,
                null,
                (string) $recovery->amount_due,
                ($recovery->created_at?->format('YmdHis') ?? '').'R'.$recovery->id,
                $recovery->vendor_id ? '/vendors/'.$recovery->vendor_id : null,
                $recovery->project_id ? '/projects/'.$recovery->project_id : null,
            );
        }

        $refundQuery = $vendor->refunds();
        $this->constrainProject($refundQuery, 'project_id', $projectId, $projectIds);
        if ($from) {
            $refundQuery->whereDate('refund_date', '>=', $from);
        }
        if ($to) {
            $refundQuery->whereDate('refund_date', '<=', $to);
        }

        foreach ($refundQuery->with('project')->orderBy('refund_date')->orderBy('id')->get() as $refund) {
            /** @var VendorRefund $refund */
            $rows[] = $this->row(
                $refund->refund_date?->toDateString(),
                'Refund Received',
                $refund->reference_number ?? 'Refund '.$refund->id,
                $refund->project?->project_name,
                (string) $refund->amount,
                null,
                $refund->refund_date?->format('Ymd').'F'.$refund->id,
                $refund->vendor_id ? '/vendors/'.$refund->vendor_id : null,
                $refund->project_id ? '/projects/'.$refund->project_id : null,
            );
        }

        foreach ($this->approvedNotes($vendor->debitNotes(), DebitNote::class, $projectId, $from, $to, $projectIds) as $note) {
            $rows[] = $note;
        }

        foreach ($this->approvedNotes($vendor->creditNotes(), CreditNote::class, $projectId, $from, $to, $projectIds) as $note) {
            $rows[] = $note;
        }

        usort($rows, fn (array $left, array $right) => strcmp((string) $left['sort'], (string) $right['sort']));

        return array_map(function (array $row) {
            unset($row['sort']);

            return $row;
        }, $rows);
    }

    /**
     * @param  HasMany<DebitNote|CreditNote>  $relation
     * @param  class-string<DebitNote|CreditNote>  $type
     * @param  list<int>|null  $projectIds
     * @return list<array<string, mixed>>
     */
    private function approvedNotes($relation, string $type, ?int $projectId, ?string $from, ?string $to, ?array $projectIds = null): array
    {
        $query = $relation->where('status', DocumentStatus::APPROVED);
        $this->constrainProject($query, 'project_id', $projectId, $projectIds);
        if ($from) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        $label = $type === DebitNote::class ? 'Debit Note' : 'Credit Note';
        $prefix = $type === DebitNote::class ? 'D' : 'C';
        $rows = [];

        foreach ($query->with('project')->orderBy('date')->orderBy('id')->get() as $note) {
            $noteHref = $note->project_id
                ? '/projects/'.$note->project_id.'/'.($type === DebitNote::class ? 'debit-notes' : 'credit-notes').'/'.$note->id
                : null;
            $rows[] = $this->row(
                $note->date?->toDateString(),
                $label,
                $note->number,
                $note->project?->project_name,
                (string) $note->total_amount,
                null,
                $note->date?->format('Ymd').$prefix.$note->id,
                $noteHref,
                $note->project_id ? '/projects/'.$note->project_id : null,
            );
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        ?string $date,
        string $type,
        ?string $reference,
        ?string $project,
        ?string $debit,
        ?string $credit,
        string $sort,
        ?string $hrefReference = null,
        ?string $hrefProject = null,
        ?string $poNumber = null,
        ?string $hrefPo = null,
    ): array {
        return [
            'date' => $date,
            'type' => $type,
            'reference' => $reference,
            'project' => $project,
            'po_number' => $poNumber,
            'debit' => $debit ? Money::scale($debit, 2) : null,
            'credit' => $credit ? Money::scale($credit, 2) : null,
            'href_reference' => $hrefReference,
            'href_project' => $hrefProject,
            'href_po' => $hrefPo,
            'sort' => $sort,
        ];
    }

    /**
     * @param  list<int>|null  $projectIds
     */
    private function constrainProject($query, string $column, ?int $projectId, ?array $projectIds): void
    {
        if ($projectId !== null) {
            $query->where($column, $projectId);

            return;
        }

        if ($projectIds !== null) {
            $query->whereIn($column, $projectIds ?: [0]);
        }
    }
}
