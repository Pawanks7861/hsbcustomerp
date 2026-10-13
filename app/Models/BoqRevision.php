<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoqRevision extends Model
{
    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    protected $fillable = [
        'boq_id',
        'revision_number',
        'revision_date',
        'revision_reason',
        'status',
        'created_by',
    ];

    protected $casts = [
        'revision_date' => 'date',
    ];

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::FINALIZED;
    }
}
