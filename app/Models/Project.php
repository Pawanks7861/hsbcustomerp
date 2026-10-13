<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'project_name',
        'project_code',
        'description',
    ];

    public function boqs(): HasMany
    {
        return $this->hasMany(Boq::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    public function materialReceipts(): HasMany
    {
        return $this->receipts();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->invoices();
    }

    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(InventoryLocation::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (app(\App\Services\Access::class)->seesAllProjects($user)) {
            return $query;
        }

        return $query->whereIn('id', $user->projects()->select('projects.id'));
    }
}
