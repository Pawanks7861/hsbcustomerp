<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'batch_number',
        'module',
        'original_filename',
        'stored_path',
        'duplicate_mode',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'summary',
        'error_report',
        'project_id',
        'uploaded_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'error_report' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
