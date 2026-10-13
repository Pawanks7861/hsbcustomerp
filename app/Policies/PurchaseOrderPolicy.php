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
        return $user->isActive() && $purchaseOrder->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive() && $purchaseOrder->status === DocumentStatus::DRAFT;
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive()
            && $purchaseOrder->status === DocumentStatus::DRAFT
            && $purchaseOrder->items()->exists();
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return app(Access::class)->allows($user, 'purchase_orders.approve')
            && $user->isActive()
            && $purchaseOrder->status === DocumentStatus::SUBMITTED;
    }

    public function reject(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->approve($user, $purchaseOrder);
    }

    public function reopen(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isActive() && $purchaseOrder->status === DocumentStatus::REJECTED;
    }
}
