<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Access;
use App\Support\DocumentStatus;

class PurchaseRequestPolicy
{
    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($purchaseRequest->status === DocumentStatus::DRAFT) {
            return true;
        }

        return $purchaseRequest->status === DocumentStatus::APPROVED
            && $purchaseRequest->purchaseOrders()
                ->where('status', '!=', DocumentStatus::REJECTED)
                ->exists();
    }

    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isActive()
            && $purchaseRequest->status === DocumentStatus::DRAFT
            && ! $purchaseRequest->purchaseOrders()->exists();
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isActive()
            && $purchaseRequest->status === DocumentStatus::DRAFT
            && $purchaseRequest->items()->exists();
    }

    public function approve(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return app(Access::class)->allows($user, 'purchase_requests.approve')
            && $user->isActive()
            && $purchaseRequest->status === DocumentStatus::SUBMITTED;
    }

    public function reject(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->approve($user, $purchaseRequest);
    }

    public function reopen(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isActive() && $purchaseRequest->status === DocumentStatus::REJECTED;
    }

    public function createPurchaseOrder(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->isActive() && $purchaseRequest->status === DocumentStatus::APPROVED;
    }
}
