<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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
        [$data, $items] = $this->validatePayload($request, $project, $modelClass);
        $total = $this->totals($data);

        return DB::transaction(function () use ($request, $project, $modelClass, $numberPrefix, $data, $total, $items) {
            /** @var DebitNote|CreditNote $note */
            $note = $modelClass::query()->create([
                ...$data,
                'project_id' => $project->id,
                'number' => DocumentNumber::next($modelClass, 'number', $numberPrefix),
                'total_amount' => $total,
                'status' => DocumentStatus::DRAFT,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($note, $items);

            return $note;
        });
    }

    /**
     * @param  class-string<DebitNote|CreditNote>  $modelClass
     */
    public function update(Request $request, Project $project, DebitNote|CreditNote $note, string $modelClass): DebitNote|CreditNote
    {
        $this->assertEditable($note);
        [$data, $items] = $this->validatePayload($request, $project, $modelClass, $note);
        $total = $this->totals($data);

        return DB::transaction(function () use ($note, $data, $total, $items) {
            $note->fill([...$data, 'total_amount' => $total])->save();
            $this->syncItems($note, $items);

            return $note->fresh();
        });
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
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>|null}
     */
    private function validatePayload(Request $request, Project $project, string $modelClass, ?Model $existing = null): array
    {
        $itemMode = $modelClass === DebitNote::class && $request->filled('items');
        $rules = [
            'date' => ['required', 'date'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'purchase_invoice_id' => ['nullable', 'integer', 'exists:purchase_invoices,id'],
            'reason' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'subtotal' => [$itemMode ? 'nullable' : 'required', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'round_off' => ['nullable', 'numeric'],
        ];

        if ($modelClass === CreditNote::class) {
            $rules['vendor_reference'] = ['nullable', 'string', 'max:255'];
        }

        if ($modelClass === DebitNote::class) {
            $rules['items'] = ['nullable', 'array'];
            $rules['items.*.purchase_order_item_id'] = ['nullable', 'integer'];
            $rules['items.*.description'] = ['nullable', 'string', 'max:1000'];
            $rules['items.*.unit'] = ['nullable', 'string', 'max:30'];
            $rules['items.*.quantity'] = ['nullable', 'numeric'];
            $rules['items.*.rate'] = ['nullable', 'numeric'];
            $rules['items.*.remarks'] = ['nullable', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules);

        $vendor = Vendor::query()->findOrFail((int) $validated['vendor_id']);
        $order = null;

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

        $items = $modelClass === DebitNote::class
            ? $this->normalizedItems($validated['items'] ?? [], $order)
            : null;

        $subtotal = $items !== null
            ? collect($items)->reduce(fn (string $sum, array $line) => Money::add($sum, $line['amount']), '0.00')
            : Money::scale((string) ($validated['subtotal'] ?? 0), 2);

        $payload = [
            'date' => $validated['date'],
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $validated['purchase_order_id'] ?? null,
            'purchase_invoice_id' => $validated['purchase_invoice_id'] ?? null,
            'reason' => $validated['reason'],
            'remarks' => $validated['remarks'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => Money::scale((string) ($validated['tax_amount'] ?? 0), 2),
            'round_off' => Money::scale((string) ($validated['round_off'] ?? 0), 2),
        ];

        if ($modelClass === CreditNote::class) {
            $payload['vendor_reference'] = $validated['vendor_reference'] ?? null;
        }

        return [$payload, $items];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>|null
     */
    private function normalizedItems(array $rows, ?PurchaseOrder $order): ?array
    {
        $items = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            $quantity = Money::scale($row['quantity'] ?? null, 3);
            $rate = Money::scale($row['rate'] ?? null, 2);
            $blank = $description === '' && ($quantity === null || bccomp($quantity, '0', 3) === 0) && ($rate === null || bccomp($rate, '0', 2) === 0);

            if ($blank) {
                continue;
            }

            if ($description === '') {
                throw ValidationException::withMessages([
                    "items.$index.description" => 'Enter an item description.',
                ]);
            }

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Enter a quantity greater than zero.',
                ]);
            }

            if ($rate === null || bccomp($rate, '0', 2) === -1) {
                throw ValidationException::withMessages([
                    "items.$index.rate" => 'Enter a rate of zero or more.',
                ]);
            }

            $poItemId = isset($row['purchase_order_item_id']) && $row['purchase_order_item_id'] !== ''
                ? (int) $row['purchase_order_item_id']
                : null;
            $unit = trim((string) ($row['unit'] ?? ''));

            if ($poItemId) {
                $poItem = PurchaseOrderItem::query()->find($poItemId);
                if (! $poItem || ! $order || (int) $poItem->purchase_order_id !== (int) $order->id) {
                    throw ValidationException::withMessages([
                        "items.$index.purchase_order_item_id" => 'The item must belong to the selected purchase order.',
                    ]);
                }
                $unit = $unit !== '' ? $unit : (string) $poItem->unit;
                if ($description === '') {
                    $description = (string) $poItem->description;
                }
            }

            $items[] = [
                'purchase_order_item_id' => $poItemId,
                'description' => $description,
                'unit' => $unit !== '' ? $unit : null,
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => Money::itemAmount($quantity, $rate) ?? '0.00',
                'remarks' => isset($row['remarks']) ? trim((string) $row['remarks']) ?: null : null,
            ];
        }

        return $items === [] ? null : $items;
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     */
    private function syncItems(DebitNote|CreditNote $note, ?array $items): void
    {
        if (! $note instanceof DebitNote) {
            return;
        }

        $note->items()->delete();

        if ($items === null) {
            return;
        }

        $note->items()->createMany($items);
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
