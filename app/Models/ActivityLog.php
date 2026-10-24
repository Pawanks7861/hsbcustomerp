<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_ACTION = 'action';

    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_LOGIN_FAILED = 'login_failed';

    public const EVENT_PASSWORD_RESET = 'password_reset';

    public const EVENT_PASSWORD_RESET_REQUEST = 'password_reset_request';

    public const EVENT_LOGIN_LOCKOUT = 'login_lockout';

    public const EVENT_RATE_LIMITED = 'rate_limited';

    public const EVENTS = [
        self::EVENT_ACTION => 'Action',
        self::EVENT_CREATED => 'Created',
        self::EVENT_UPDATED => 'Updated',
        self::EVENT_DELETED => 'Deleted',
        self::EVENT_LOGIN => 'Login',
        self::EVENT_LOGOUT => 'Logout',
        self::EVENT_LOGIN_FAILED => 'Failed login',
        self::EVENT_PASSWORD_RESET => 'Password reset',
        self::EVENT_PASSWORD_RESET_REQUEST => 'Password reset request',
        self::EVENT_LOGIN_LOCKOUT => 'Login lockout',
        self::EVENT_RATE_LIMITED => 'Rate limited',
    ];

    protected $fillable = [
        'user_id',
        'action',
        'event',
        'subject_type',
        'subject_id',
        'subject_label',
        'project_id',
        'properties',
        'ip_address',
        'user_agent',
        'method',
        'url',
        'batch_id',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
