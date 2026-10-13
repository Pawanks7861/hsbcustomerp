<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    public const OPENING = 'opening';

    public const INCREASE = 'increase';

    public const DECREASE = 'decrease';

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'project_id',
        'material_id',
        'adjustment_number',
        'adjustment_date',
        'adjustment_type',
        'quantity',
        'reason',
        'attachment_path',
        'status',
        'created_by',
        'posted_at',
        'posted_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'quantity' => 'decimal:3',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function increasesStock(): bool
    {
        return in_array($this->adjustment_type, [self::OPENING, self::INCREASE], true);
    }
}
