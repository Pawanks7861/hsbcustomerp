<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorRecovery;
use App\Models\VendorRefund;
use App\Services\VendorBalanceService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VendorRecoveryController extends Controller
{
    public function store(Request $request, Vendor $vendor, VendorBalanceService $balances): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'amount_due' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = Money::scale($validated['amount_due'], 2);
        $available = $balances->availableAdvance($vendor);

        if (bccomp($amount, $available, 2) === 1) {
            throw ValidationException::withMessages([
                'amount_due' => 'Refund cannot exceed the available advance.',
            ]);
        }

        $vendor->recoveries()->create([
            'project_id' => $validated['project_id'] ?? null,
            'amount_due' => $amount,
            'amount_received' => '0.00',
            'recovery_date' => now()->toDateString(),
            'status' => VendorRecovery::OPEN,
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Advance marked for refund.');
    }

    public function receive(Request $request, Vendor $vendor, VendorRecovery $recovery): RedirectResponse
    {
        abort_unless($recovery->vendor_id === $vendor->id, 404);

        $validated = $request->validate([
            'refund_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'refund_mode' => ['required', Rule::in(VendorRefund::MODES)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = Money::scale($validated['amount'], 2);

        if (bccomp($amount, $recovery->remaining(), 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => 'Refund received cannot exceed the refund due.',
            ]);
        }

        DB::transaction(function () use ($request, $vendor, $recovery, $validated, $amount): void {
            $vendor->refunds()->create([
                'vendor_recovery_id' => $recovery->id,
                'project_id' => $recovery->project_id,
                'refund_date' => $validated['refund_date'],
                'amount' => $amount,
                'refund_mode' => $validated['refund_mode'],
                'reference_number' => $validated['reference_number'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $received = Money::add((string) $recovery->amount_received, $amount);
            $status = bccomp($received, (string) $recovery->amount_due, 2) >= 0
                ? VendorRecovery::RECOVERED
                : VendorRecovery::PARTIAL;

            $recovery->update([
                'amount_received' => $received,
                'status' => $status,
            ]);
        });

        return back()->with('success', 'Refund received.');
    }
}
