<?php

namespace App\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const VENDOR = 'vendor';

    public const CONTRACTOR = 'contractor';

    public const BOTH = 'both';

    protected $fillable = [
        'vendor_code',
        'party_type',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'pincode',
        'gst_number',
        'pan_number',
        'bank_name',
        'account_number',
        'ifsc',
        'opening_advance',
        'opening_outstanding',
        'status',
        'notes',
    ];

    protected $casts = [
        'opening_advance' => 'decimal:2',
        'opening_outstanding' => 'decimal:2',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(VendorAdvanceAdjustment::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(VendorRecovery::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(VendorRefund::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isContractor(): bool
    {
        return in_array($this->party_type, [self::CONTRACTOR, self::BOTH], true);
    }

    public function scopeContractors($query)
    {
        return $query->whereIn('party_type', [self::CONTRACTOR, self::BOTH])
            ->where('status', self::ACTIVE);
    }

    public function hasTransactions(): bool
    {
        return $this->purchaseOrders()->exists()
            || $this->receipts()->exists()
            || $this->invoices()->exists()
            || $this->payments()->exists()
            || $this->adjustments()->exists()
            || $this->recoveries()->exists()
            || $this->refunds()->exists()
            || $this->workOrders()->exists()
            || $this->debitNotes()->exists()
            || $this->creditNotes()->exists()
            || $this->expenses()->exists();
    }
}
