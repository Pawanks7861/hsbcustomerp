<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\Vendor;
use App\Models\VendorAdvanceAdjustment;
use App\Models\VendorPayment;
use App\Models\VendorRecovery;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class VendorBalanceService
{
    /**
     * @return array<string, string>
     */
    public function summary(Vendor $vendor, ?int $projectId = null, ?string $from = null, ?string $to = null): array
    {
        $filtered = $projectId !== null || $from !== null || $to !== null;
        $advancePaid = $this->sumPayments($vendor, VendorPayment::TYPE_ADVANCE, $projectId, $from, $to);
        $invoicePayments = $this->sumPayments($vendor, VendorPayment::TYPE_INVOICE, $projectId, $from, $to);
        $otherPayments = $this->sumPayments($vendor, VendorPayment::TYPE_OTHER, $projectId, $from, $to);

        if (! $filtered) {
            $advancePaid = Money::add($advancePaid, (string) $vendor->opening_advance);
        }

        $invoiceTotal = $this->sumInvoices($vendor, $projectId, $from, $to);
        $adjusted = $this->sumAdjustments($vendor, $projectId, $from, $to);
        $marked = $this->sumMarkedRefunds($vendor, $projectId, $from, $to);
        $refundReceived = $this->sumRefundsReceived($vendor, $projectId, $from, $to);
        $poTotal = $this->sumApprovedPos($vendor, $projectId, $from, $to);

        $available = Money::sub(Money::sub($advancePaid, $adjusted), $marked);
        $outstanding = Money::sub(Money::sub($invoiceTotal, $invoicePayments), $adjusted);

        if (! $filtered) {
            $outstanding = Money::add($outstanding, (string) $vendor->opening_outstanding);
        }

        $refundDue = Money::sub($marked, $refundReceived);

        return [
            'total_po_value' => $poTotal,
            'total_invoice_value' => $invoiceTotal,
            'total_paid' => Money::add(Money::add($advancePaid, $invoicePayments), $otherPayments),
            'advance_paid' => $advancePaid,
            'advance_adjusted' => $adjusted,
            'available_advance' => $this->floor($available),
            'invoice_payments' => $invoicePayments,
            'outstanding_payable' => $this->floor($outstanding),
            'marked_for_refund' => $marked,
            'refund_received' => $refundReceived,
            'refund_due' => $this->floor($refundDue),
        ];
    }

    public function availableAdvance(Vendor $vendor): string
    {
        return $this->summary($vendor)['available_advance'];
    }

    public function invoiceOutstanding(PurchaseInvoice $invoice): string
    {
        $paid = (string) $invoice->payments()->sum('amount');
        $adjusted = (string) $invoice->adjustments()->sum('amount');
        $outstanding = Money::sub(Money::sub((string) $invoice->total_amount, $this->money($paid)), $this->money($adjusted));

        return $this->floor($outstanding);
    }

    public function directInvoicePayments(PurchaseInvoice $invoice): string
    {
        return $this->money((string) $invoice->payments()->sum('amount'));
    }

    public function advanceApplied(PurchaseInvoice $invoice): string
    {
        return $this->money((string) $invoice->adjustments()->sum('amount'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transactions(Vendor $vendor): array
    {
        $rows = [];

        foreach ($vendor->payments()->with(['project', 'invoice'])->orderBy('payment_date')->orderBy('id')->get() as $payment) {
            $rows[] = [
                'date' => $payment->payment_date?->toDateString(),
                'type' => $payment->typeLabel(),
                'project' => $payment->project?->project_name,
                'reference' => $payment->invoice?->invoice_number ?? $payment->reference_number,
                'payment' => (string) $payment->amount,
                'refund' => null,
                'remarks' => $payment->remarks,
                'sort' => $payment->payment_date?->format('Ymd').$payment->id,
            ];
        }

        foreach ($vendor->adjustments()->with(['invoice.project'])->orderBy('adjustment_date')->orderBy('id')->get() as $adjustment) {
            $rows[] = [
                'date' => $adjustment->adjustment_date?->toDateString(),
                'type' => 'Advance Adjustment',
                'project' => $adjustment->invoice?->project?->project_name,
                'reference' => $adjustment->invoice?->invoice_number,
                'payment' => (string) $adjustment->amount,
                'refund' => null,
                'remarks' => $adjustment->remarks,
                'sort' => $adjustment->adjustment_date?->format('Ymd').$adjustment->id,
            ];
        }

        foreach ($vendor->recoveries()->with('project')->orderBy('created_at')->orderBy('id')->get() as $recovery) {
            $rows[] = [
                'date' => $recovery->recovery_date?->toDateString() ?? $recovery->created_at?->toDateString(),
                'type' => 'Refund Due',
                'project' => $recovery->project?->project_name,
                'reference' => 'Recovery '.$recovery->id,
                'payment' => null,
                'refund' => (string) $recovery->amount_due,
                'remarks' => $recovery->remarks,
                'sort' => ($recovery->created_at?->format('YmdHis') ?? '').$recovery->id,
            ];
        }

        foreach ($vendor->refunds()->with('project')->orderBy('refund_date')->orderBy('id')->get() as $refund) {
            $rows[] = [
                'date' => $refund->refund_date?->toDateString(),
                'type' => 'Refund Received',
                'project' => $refund->project?->project_name,
                'reference' => $refund->reference_number,
                'payment' => null,
                'refund' => (string) $refund->amount,
                'remarks' => $refund->remarks,
                'sort' => $refund->refund_date?->format('Ymd').$refund->id,
            ];
        }

        usort($rows, fn (array $left, array $right) => strcmp((string) $right['sort'], (string) $left['sort']));

        return array_map(function (array $row) {
            unset($row['sort']);

            return $row;
        }, $rows);
    }

    public function refreshInvoice(PurchaseInvoice $invoice): void
    {
        $outstanding = $this->invoiceOutstanding($invoice);
        $total = (string) $invoice->total_amount;
        $status = PurchaseInvoice::STATUS_RECORDED;

        if (bccomp($outstanding, '0.00', 2) !== 1) {
            $status = PurchaseInvoice::STATUS_PAID;
        } elseif (bccomp($outstanding, $total, 2) === -1) {
            $status = PurchaseInvoice::STATUS_PARTIAL;
        }

        $invoice->forceFill(['status' => $status])->save();
    }

    private function sumPayments(Vendor $vendor, string $type, ?int $projectId, ?string $from, ?string $to): string
    {
        $query = $vendor->payments()->where('payment_type', $type);
        $this->restrict($query, 'project_id', 'payment_date', $projectId, $from, $to);

        return $this->money((string) $query->sum('amount'));
    }

    private function sumInvoices(Vendor $vendor, ?int $projectId, ?string $from, ?string $to): string
    {
        $query = $vendor->invoices();
        $this->restrict($query, 'project_id', 'invoice_date', $projectId, $from, $to);

        return $this->money((string) $query->sum('total_amount'));
    }

    private function sumAdjustments(Vendor $vendor, ?int $projectId, ?string $from, ?string $to): string
    {
        $query = VendorAdvanceAdjustment::query()->where('vendor_id', $vendor->id);

        if ($projectId !== null) {
            $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('project_id', $projectId));
        }

        if ($from) {
            $query->whereDate('adjustment_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('adjustment_date', '<=', $to);
        }

        return $this->money((string) $query->sum('amount'));
    }

    private function sumMarkedRefunds(Vendor $vendor, ?int $projectId, ?string $from, ?string $to): string
    {
        $query = VendorRecovery::query()->where('vendor_id', $vendor->id);
        $this->restrict($query, 'project_id', 'created_at', $projectId, $from, $to);

        return $this->money((string) $query->sum('amount_due'));
    }

    private function sumRefundsReceived(Vendor $vendor, ?int $projectId, ?string $from, ?string $to): string
    {
        $query = $vendor->refunds();
        $this->restrict($query, 'project_id', 'refund_date', $projectId, $from, $to);

        return $this->money((string) $query->sum('amount'));
    }

    private function sumApprovedPos(Vendor $vendor, ?int $projectId, ?string $from, ?string $to): string
    {
        $query = $vendor->purchaseOrders()->where('status', DocumentStatus::APPROVED);
        $this->restrict($query, 'project_id', 'po_date', $projectId, $from, $to);

        return $this->money((string) $query->sum('grand_total'));
    }

    private function restrict(Builder|Relation $query, string $projectColumn, string $dateColumn, ?int $projectId, ?string $from, ?string $to): void
    {
        if ($projectId !== null) {
            $query->where($projectColumn, $projectId);
        }

        if ($from) {
            $query->whereDate($dateColumn, '>=', $from);
        }

        if ($to) {
            $query->whereDate($dateColumn, '<=', $to);
        }
    }

    private function money(string $value): string
    {
        if ($value === '' || ! is_numeric($value)) {
            return '0.00';
        }

        return Money::scale($value, 2) ?? '0.00';
    }

    private function floor(string $value): string
    {
        return bccomp($value, '0', 2) === -1 ? '0.00' : $value;
    }
}
