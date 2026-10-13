<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    protected $fillable = [
        'user_id',
        'permission',
        'effect',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
