<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access;
use App\Support\DocumentStatus;

class PurchaseOrderPolicy
{
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive()
            && app(Access::class)->allows($user, 'purchase_orders.edit')
            && $purchaseOrder->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive()
            && app(Access::class)->allows($user, 'purchase_orders.delete')
            && $purchaseOrder->status === DocumentStatus::DRAFT;
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive()
            && app(Access::class)->allows($user, 'purchase_orders.submit')
            && $purchaseOrder->status === DocumentStatus::DRAFT
            && $purchaseOrder->items()->exists();
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return app(Access::class)->allows($user, 'purchase_orders.approve')
            && $user->isActive()
            && $purchaseOrder->status === DocumentStatus::SUBMITTED
            && ! $purchaseOrder->second_approved_by
            && (int) $purchaseOrder->first_approved_by !== (int) $user->id;
    }

    public function print(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->printable()
            && $user->isActive()
            && (
                app(Access::class)->allows($user, 'purchase_orders.print')
                || app(Access::class)->allows($user, 'purchase_orders.view')
                || $user->isSuperAdmin()
            );
    }

    public function reject(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return app(Access::class)->allows($user, 'purchase_orders.reject')
            && $user->isActive()
            && $purchaseOrder->status === DocumentStatus::SUBMITTED
            && ! $purchaseOrder->second_approved_by;
    }

    public function reopen(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive()
            && app(Access::class)->allows($user, 'purchase_orders.edit')
            && $purchaseOrder->status === DocumentStatus::REJECTED;
    }
}
