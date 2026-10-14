<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    public const TAX_RATES = ['0', '5', '12', '18', '28'];

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_PARTIAL = 'partially_paid';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'project_id',
        'purchase_order_id',
        'vendor_id',
        'invoice_number',
        'invoice_date',
        'invoice_amount',
        'tax_percentage',
        'tax_amount',
        'total_amount',
        'due_date',
        'invoice_file_path',
        'remarks',
        'status',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'invoice_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(VendorAdvanceAdjustment::class);
    }

    public function hasSettlements(): bool
    {
        return $this->payments()->exists() || $this->adjustments()->exists();
    }
}
