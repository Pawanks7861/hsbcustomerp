<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorNoteService
{
    /**
     * @param  class-string<DebitNote|CreditNote>  $modelClass
     */
    public function store(Request $request, Project $project, string $modelClass, string $numberPrefix): DebitNote|CreditNote
    {
        $data = $this->validatePayload($request, $project, $modelClass);
        $total = $this->totals($data);

        return DB::transaction(function () use ($request, $project, $modelClass, $numberPrefix, $data, $total) {
            /** @var DebitNote|CreditNote $note */
            $note = $modelClass::query()->create([
                ...$data,
                'project_id' => $project->id,
                'number' => DocumentNumber::next($modelClass, 'number', $numberPrefix),
                'total_amount' => $total,
                'status' => DocumentStatus::DRAFT,
                'created_by' => $request->user()?->id,
            ]);

            return $note;
        });
    }

    /**
     * @param  class-string<DebitNote|CreditNote>  $modelClass
     */
    public function update(Request $request, Project $project, DebitNote|CreditNote $note, string $modelClass): DebitNote|CreditNote
    {
        $this->assertEditable($note);
        $data = $this->validatePayload($request, $project, $modelClass, $note);
        $total = $this->totals($data);

        $note->fill([...$data, 'total_amount' => $total])->save();

        return $note->fresh();
    }

    public function submit(DebitNote|CreditNote $note): void
    {
        if ($note->status !== DocumentStatus::DRAFT && $note->status !== DocumentStatus::REJECTED) {
            throw ValidationException::withMessages(['status' => 'Only draft or rejected notes can be submitted.']);
        }

        $note->forceFill([
            'status' => DocumentStatus::SUBMITTED,
            'rejected_by' => null,
            'rejection_reason' => null,
        ])->save();
    }

    public function approve(DebitNote|CreditNote $note, User $user): void
    {
        if ($note->status !== DocumentStatus::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Only submitted notes can be approved.']);
        }

        $note->forceFill([
            'status' => DocumentStatus::APPROVED,
            'approved_by' => $user->id,
            'rejected_by' => null,
            'rejection_reason' => null,
        ])->save();
    }

    public function reject(DebitNote|CreditNote $note, User $user, ?string $reason): void
    {
        if ($note->status !== DocumentStatus::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Only submitted notes can be rejected.']);
        }

        $note->forceFill([
            'status' => DocumentStatus::REJECTED,
            'rejected_by' => $user->id,
            'rejection_reason' => $reason,
            'approved_by' => null,
        ])->save();
    }

    /**
     * @param  class-string<DebitNote|CreditNote>  $modelClass
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, Project $project, string $modelClass, ?Model $existing = null): array
    {
        $rules = [
            'date' => ['required', 'date'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'purchase_invoice_id' => ['nullable', 'integer', 'exists:purchase_invoices,id'],
            'reason' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'round_off' => ['nullable', 'numeric'],
        ];

        if ($modelClass === CreditNote::class) {
            $rules['vendor_reference'] = ['nullable', 'string', 'max:255'];
        }

        $validated = $request->validate($rules);

        $vendor = Vendor::query()->findOrFail((int) $validated['vendor_id']);

        if ($validated['purchase_order_id'] ?? null) {
            $order = PurchaseOrder::query()->findOrFail((int) $validated['purchase_order_id']);
            if ((int) $order->project_id !== (int) $project->id || (int) $order->vendor_id !== (int) $vendor->id) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Purchase order must belong to this project and vendor.']);
            }
        }

        if ($validated['purchase_invoice_id'] ?? null) {
            $invoice = PurchaseInvoice::query()->findOrFail((int) $validated['purchase_invoice_id']);
            if ((int) $invoice->project_id !== (int) $project->id || (int) $invoice->vendor_id !== (int) $vendor->id) {
                throw ValidationException::withMessages(['purchase_invoice_id' => 'Invoice must belong to this project and vendor.']);
            }
        }

        $payload = [
            'date' => $validated['date'],
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $validated['purchase_order_id'] ?? null,
            'purchase_invoice_id' => $validated['purchase_invoice_id'] ?? null,
            'reason' => $validated['reason'],
            'remarks' => $validated['remarks'] ?? null,
            'subtotal' => Money::scale((string) $validated['subtotal'], 2),
            'tax_amount' => Money::scale((string) ($validated['tax_amount'] ?? 0), 2),
            'round_off' => Money::scale((string) ($validated['round_off'] ?? 0), 2),
        ];

        if ($modelClass === CreditNote::class) {
            $payload['vendor_reference'] = $validated['vendor_reference'] ?? null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function totals(array $data): string
    {
        $sum = Money::add(Money::add((string) $data['subtotal'], (string) $data['tax_amount']), (string) $data['round_off']);

        return Money::scale($sum, 2) ?? '0.00';
    }

    private function assertEditable(DebitNote|CreditNote $note): void
    {
        if (! in_array($note->status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true)) {
            throw ValidationException::withMessages(['status' => 'Approved or submitted notes cannot be edited.']);
        }
    }
}
