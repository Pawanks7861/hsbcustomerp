<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceItem;
use App\Models\ClientPayment;
use App\Models\Project;
use App\Models\User;
use App\Support\DocumentStatus;
use App\Support\Money;
use App\Support\PurchaseLines;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientInvoiceService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function billableItems(Project $project, ?int $exceptInvoiceId = null): array
    {
        $boq = $project->boqs()->orderByDesc('id')->first();
        if (! $boq instanceof Boq || ! $boq->current_revision_id) {
            return [];
        }

        $items = BoqItem::query()
            ->where('boq_id', $boq->id)
            ->where('boq_revision_id', $boq->current_revision_id)
            ->where('is_section', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $billed = $this->billedQuantities($items->pluck('id')->map(fn ($id) => (int) $id)->all(), $exceptInvoiceId);

        return $items->map(function (BoqItem $item) use ($billed) {
            $boqQty = Money::scale($item->quantity, 3) ?? '0.000';
            $previous = $billed[$item->id] ?? '0.000';
            $remaining = Money::sub($boqQty, $previous, 3);
            if (bccomp($remaining, '0', 3) === -1) {
                $remaining = '0.000';
            }

            return [
                'id' => $item->id,
                'item_number' => $item->item_number,
                'description' => $item->description,
                'unit' => $item->unit,
                'boq_quantity' => $boqQty,
                'previously_billed' => $previous,
                'remaining_quantity' => $remaining,
                'rate' => Money::scale($item->rate, 2) ?? '0.00',
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, int>  $boqItemIds
     * @return array<int, string>
     */
    public function billedQuantities(array $boqItemIds, ?int $exceptInvoiceId = null): array
    {
        if ($boqItemIds === []) {
            return [];
        }

        $rows = ClientInvoiceItem::query()
            ->selectRaw('client_invoice_items.boq_item_id, SUM(client_invoice_items.quantity) as billed')
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_items.client_invoice_id')
            ->whereIn('client_invoice_items.boq_item_id', $boqItemIds)
            ->where('client_invoices.approval_status', '!=', DocumentStatus::REJECTED)
            ->when($exceptInvoiceId, fn ($query) => $query->where('client_invoices.id', '!=', $exceptInvoiceId))
            ->groupBy('client_invoice_items.boq_item_id')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->boq_item_id] = Money::scale((string) $row->billed, 3) ?? '0.000';
        }

        return $totals;
    }

    public function persist(Request $request, Project $project, User $user, ?ClientInvoice $existing = null): ClientInvoice
    {
        if ($existing && ! $existing->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft or rejected invoices can be edited.']);
        }

        $items = $request->input('items', []);
        if (is_array($items)) {
            foreach ($items as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['boq_item_id'] ?? '') === '' || ($row['boq_item_id'] ?? null) === null) {
                    $items[$index]['boq_item_id'] = null;
                }
            }
            $request->merge(['items' => $items]);
        }

        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'place_of_supply' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'gst_percentage' => ['required', Rule::in(ClientInvoice::TAX_RATES)],
            'round_off' => ['nullable', 'numeric', 'between:-100,100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.rate' => ['required', 'numeric', 'gte:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'items.*.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $client = Client::query()->findOrFail($validated['client_id']);
        if (! $client->isActive() && (int) $existing?->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['client_id' => 'Select an active client.']);
        }

        $lines = $this->pricedLines($project, $validated['items'], $validated['gst_percentage'], $existing?->id);
        $commercial = ['freight' => 0, 'round_off' => $validated['round_off'] ?? 0];
        $totals = PurchaseLines::summarize($lines, $validated['gst_percentage'], $commercial);

        return DB::transaction(function () use ($project, $user, $existing, $client, $validated, $lines, $totals) {
            $header = [
                'project_id' => $project->id,
                'client_id' => $client->id,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'client_name' => $client->name,
                'client_address' => $client->billingAddress(),
                'client_gst' => $client->gst_number,
                'client_pan' => $client->pan_number,
                'place_of_supply' => $validated['place_of_supply'] ?? $client->state,
                'reference_number' => $validated['reference_number'] ?? null,
                'description' => $validated['description'] ?? null,
                'payment_terms' => $validated['payment_terms'] ?? $client->payment_terms,
                'remarks' => $validated['remarks'] ?? null,
                'gst_percentage' => $totals['gst_percentage'],
                ...$totals,
                'approval_status' => $existing?->approval_status === DocumentStatus::REJECTED
                    ? DocumentStatus::REJECTED
                    : DocumentStatus::DRAFT,
            ];

            if ($existing) {
                $existing->update($header);
                $existing->items()->delete();
                $existing->items()->createMany($this->storeableLines($lines));

                return $existing->fresh(['items', 'client']);
            }

            $invoice = ClientInvoice::query()->create([
                ...$header,
                'invoice_number' => DocumentNumber::next(ClientInvoice::class, 'invoice_number', 'CIN'),
                'payment_status' => ClientInvoice::PAYMENT_UNPAID,
                'created_by' => $user->id,
            ]);
            $invoice->items()->createMany($this->storeableLines($lines));

            return $invoice->fresh(['items', 'client']);
        });
    }

    public function submit(ClientInvoice $invoice): void
    {
        if (! in_array($invoice->approval_status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true)) {
            throw ValidationException::withMessages(['approval_status' => 'Only draft or rejected invoices can be submitted.']);
        }

        if ($invoice->items()->doesntExist()) {
            throw ValidationException::withMessages(['items' => 'Add at least one invoice line before submitting.']);
        }

        $invoice->update([
            'approval_status' => DocumentStatus::SUBMITTED,
            'submitted_at' => now(),
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);
    }

    public function approve(ClientInvoice $invoice, User $user): void
    {
        if ($invoice->approval_status !== DocumentStatus::SUBMITTED) {
            throw ValidationException::withMessages(['approval_status' => 'Only submitted invoices can be approved.']);
        }

        $invoice->update([
            'approval_status' => DocumentStatus::APPROVED,
            'approved_at' => now(),
            'approved_by' => $user->id,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);
    }

    public function reject(ClientInvoice $invoice, User $user, string $reason): void
    {
        if ($invoice->approval_status !== DocumentStatus::SUBMITTED) {
            throw ValidationException::withMessages(['approval_status' => 'Only submitted invoices can be rejected.']);
        }

        $invoice->update([
            'approval_status' => DocumentStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $user->id,
            'rejection_reason' => $reason,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    public function recordPayment(Request $request, ClientInvoice $invoice, User $user): ClientPayment
    {
        if (! $invoice->isApproved()) {
            throw ValidationException::withMessages(['client_invoice_id' => 'Payments can only be recorded against an approved invoice.']);
        }

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'payment_mode' => ['required', Rule::in(ClientPayment::MODES)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = Money::scale($validated['amount'], 2) ?? '0.00';
        $reference = trim((string) ($validated['reference_number'] ?? ''));
        $reference = $reference === '' ? null : $reference;
        $outstanding = $invoice->outstanding();

        if (bccomp($amount, $outstanding, 2) === 1) {
            throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding balance of '.$outstanding.'.']);
        }

        if ($reference !== null && $invoice->payments()->where('reference_number', $reference)->exists()) {
            throw ValidationException::withMessages(['reference_number' => 'This payment reference was already recorded for the invoice.']);
        }

        try {
            return DB::transaction(function () use ($invoice, $user, $validated, $amount, $reference) {
                $locked = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $locked->load('payments');
                $outstanding = $locked->outstanding();
                if (bccomp($amount, $outstanding, 2) === 1) {
                    throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding balance of '.$outstanding.'.']);
                }

                if ($reference !== null && $locked->payments->contains(fn (ClientPayment $payment) => $payment->reference_number === $reference)) {
                    throw ValidationException::withMessages(['reference_number' => 'This payment reference was already recorded for the invoice.']);
                }

                $payment = ClientPayment::query()->create([
                    'client_id' => $locked->client_id,
                    'project_id' => $locked->project_id,
                    'client_invoice_id' => $locked->id,
                    'payment_date' => $validated['payment_date'],
                    'payment_mode' => $validated['payment_mode'],
                    'amount' => $amount,
                    'reference_number' => $reference,
                    'remarks' => $validated['remarks'] ?? null,
                    'created_by' => $user->id,
                ]);

                $this->refreshPaymentStatus($locked->fresh('payments'));

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference_number' => 'This payment reference was already recorded for the invoice.']);
        }
    }

    public function refreshPaymentStatus(ClientInvoice $invoice): void
    {
        $paid = $invoice->paidTotal();
        $total = Money::scale((string) $invoice->grand_total, 2) ?? '0.00';

        if (bccomp($paid, '0.00', 2) === 0) {
            $status = ClientInvoice::PAYMENT_UNPAID;
        } elseif (bccomp($paid, $total, 2) >= 0) {
            $status = ClientInvoice::PAYMENT_PAID;
        } else {
            $status = ClientInvoice::PAYMENT_PARTIAL;
        }

        $invoice->update(['payment_status' => $status]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function pricedLines(Project $project, array $rows, string $gstPercentage, ?int $exceptInvoiceId): array
    {
        $catalog = collect($this->billableItems($project, $exceptInvoiceId))->keyBy('id');
        $prepared = [];
        $requested = [];

        foreach (array_values($rows) as $index => $row) {
            $boqItemId = (int) ($row['boq_item_id'] ?? 0) ?: null;
            $quantity = Money::scale($row['quantity'], 3);
            $rate = Money::scale($row['rate'], 2);
            $description = trim((string) $row['description']);
            $unit = trim((string) ($row['unit'] ?? ''));
            $itemNumber = null;

            if ($boqItemId) {
                $catalogItem = $catalog->get($boqItemId);
                if (! is_array($catalogItem)) {
                    throw ValidationException::withMessages([
                        "items.$index.boq_item_id" => 'Select a billable BOQ item from the current project revision.',
                    ]);
                }

                $used = $requested[$boqItemId] ?? '0.000';
                $used = Money::add($used, $quantity ?? '0.000', 3);
                if (bccomp($used, $catalogItem['remaining_quantity'], 3) === 1) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Quantity exceeds the remaining billable BOQ quantity of '.$catalogItem['remaining_quantity'].'.',
                    ]);
                }
                $requested[$boqItemId] = $used;

                $itemNumber = $catalogItem['item_number'];
                if ($description === '') {
                    $description = $catalogItem['description'];
                }
                if ($unit === '') {
                    $unit = (string) $catalogItem['unit'];
                }
            }

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Enter a quantity greater than zero.',
                ]);
            }

            $prepared[] = [
                'boq_item_id' => $boqItemId,
                'item_number' => $itemNumber,
                'description' => $description,
                'unit' => $unit === '' ? null : $unit,
                'quantity' => $quantity,
                'rate' => $rate ?? '0.00',
                'amount' => Money::itemAmount($quantity, $rate) ?? '0.00',
                'discount_percentage' => $row['discount_percentage'] ?? 0,
                'remarks' => $row['remarks'] ?? null,
            ];
        }

        return PurchaseLines::price($prepared, $gstPercentage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function storeableLines(array $lines): array
    {
        return array_map(fn (array $line) => [
            'boq_item_id' => $line['boq_item_id'] ?? null,
            'item_number' => $line['item_number'] ?? null,
            'description' => $line['description'],
            'unit' => $line['unit'] ?? null,
            'quantity' => $line['quantity'],
            'rate' => $line['rate'],
            'amount' => $line['amount'],
            'discount_percentage' => $line['discount_percentage'] ?? '0.00',
            'discount_amount' => $line['discount_amount'] ?? '0.00',
            'taxable_amount' => $line['taxable_amount'] ?? $line['amount'],
            'cgst_amount' => $line['cgst_amount'] ?? '0.00',
            'sgst_amount' => $line['sgst_amount'] ?? '0.00',
            'line_total' => $line['line_total'] ?? $line['amount'],
            'remarks' => $line['remarks'] ?? null,
        ], $lines);
    }
}
