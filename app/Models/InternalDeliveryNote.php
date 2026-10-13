<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalDeliveryNote extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const DISPATCHED = 'dispatched';

    public const PARTIAL = 'partially_received';

    public const RECEIVED = 'received';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'idn_number',
        'transfer_date',
        'source_location_id',
        'destination_location_id',
        'source_project_id',
        'destination_project_id',
        'expected_arrival_date',
        'vehicle_number',
        'driver_name',
        'remarks',
        'status',
        'requested_by',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'dispatched_at',
        'dispatched_by',
        'received_at',
        'received_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'expected_arrival_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'destination_location_id');
    }

    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }

    public function destinationProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'destination_project_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InternalDeliveryNoteItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InternalDeliveryReceipt::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IdnAttachment::class);
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOpenForReceipt(): bool
    {
        return in_array($this->status, [self::DISPATCHED, self::PARTIAL], true);
    }
}
