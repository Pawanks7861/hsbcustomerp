<?php

namespace App\Models;

use App\Support\DocumentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientInvoice extends Model
{
    public const TAX_RATES = ['0', '5', '12', '18', '28'];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partially_paid';

    public const PAYMENT_PAID = 'paid';

    protected $fillable = [
        'project_id',
        'client_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'client_name',
        'client_address',
        'client_gst',
        'client_pan',
        'place_of_supply',
        'reference_number',
        'description',
        'payment_terms',
        'remarks',
        'gst_percentage',
        'subtotal',
        'discount_total',
        'taxable_value',
        'cgst_percentage',
        'sgst_percentage',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'gst_amount',
        'freight',
        'round_off',
        'grand_total',
        'approval_status',
        'payment_status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'gst_percentage' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'taxable_value' => 'decimal:2',
        'cgst_percentage' => 'decimal:2',
        'sgst_percentage' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'freight' => 'decimal:2',
        'round_off' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientInvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClientPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === DocumentStatus::APPROVED;
    }

    public function isEditable(): bool
    {
        return in_array($this->approval_status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true)
            && ! $this->payments()->exists();
    }

    public function paidTotal(): string
    {
        $paid = '0.00';
        foreach ($this->payments as $payment) {
            $paid = Money::add($paid, Money::scale((string) $payment->amount, 2) ?? '0.00');
        }

        return $paid;
    }

    public function outstanding(): string
    {
        $total = Money::scale((string) $this->grand_total, 2) ?? '0.00';
        $paid = $this->relationLoaded('payments')
            ? $this->paidTotal()
            : (Money::scale((string) $this->payments()->sum('amount'), 2) ?? '0.00');
        $outstanding = Money::sub($total, $paid);

        return bccomp($outstanding, '0.00', 2) === -1 ? '0.00' : $outstanding;
    }

    public function isOverdue(): bool
    {
        return $this->isApproved()
            && $this->payment_status !== self::PAYMENT_PAID
            && $this->due_date
            && $this->due_date->lt(now()->startOfDay());
    }
}
