<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRecovery extends Model
{
    public const OPEN = 'open';

    public const PARTIAL = 'partial';

    public const RECOVERED = 'recovered';

    protected $fillable = [
        'vendor_id',
        'vendor_payment_id',
        'project_id',
        'amount_due',
        'amount_received',
        'recovery_date',
        'status',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'recovery_date' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(VendorRefund::class);
    }

    public function remaining(): string
    {
        return bcsub((string) $this->amount_due, (string) $this->amount_received, 2);
    }
}
