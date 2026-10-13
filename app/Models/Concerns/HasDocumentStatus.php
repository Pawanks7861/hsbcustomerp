<?php

namespace App\Models\Concerns;

use App\Support\DocumentStatus;

trait HasDocumentStatus
{
    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === DocumentStatus::SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === DocumentStatus::REJECTED;
    }
}
