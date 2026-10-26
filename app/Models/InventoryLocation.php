<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLocation extends Model
{
    public const WAREHOUSE = 'warehouse';

    public const CENTRAL_STORE = 'central_store';

    public const PROJECT_STORE = 'project_store';

    public const SITE = 'site';

    public const OTHER = 'other';

    public const IN_TRANSIT = 'in_transit';

    public const TYPES = [
        self::WAREHOUSE,
        self::CENTRAL_STORE,
        self::PROJECT_STORE,
        self::SITE,
        self::OTHER,
    ];

    protected $fillable = [
        'location_code',
        'name',
        'location_type',
        'project_id',
        'address',
        'responsible_user_id',
        'is_active',
        'is_default',
        'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function isInTransit(): bool
    {
        return $this->location_type === self::IN_TRANSIT;
    }

    public function isCentralStore(): bool
    {
        return $this->project_id === null
            && ! $this->is_system
            && ($this->location_type === self::CENTRAL_STORE || $this->location_code === 'CENTRAL');
    }
}
