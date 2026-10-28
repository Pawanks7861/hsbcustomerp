<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssueItem extends Model
{
    protected $fillable = [
        'material_issue_id',
        'material_id',
        'unit',
        'quantity',
        'unit_cost',
        'amount',
        'is_estimated',
        'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_estimated' => 'boolean',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
