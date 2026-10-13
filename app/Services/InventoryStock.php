<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\StockLedgerEntry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStock
{
    public function balance(int $projectId, int $materialId): string
    {
        return $this->sumBucket(
            StockLedgerEntry::query()
                ->where('project_id', $projectId)
                ->where('material_id', $materialId)
                ->where(function ($query): void {
                    $query->whereNull('location_id')
                        ->orWhereHas('location', fn ($location) => $location->where('location_type', '!=', InventoryLocation::IN_TRANSIT));
                })
        );
    }

    public function locationBalance(int $projectId, int $materialId, int $locationId): string
    {
        $location = InventoryLocation::query()->findOrFail($locationId);
        $query = StockLedgerEntry::query()
            ->where('project_id', $projectId)
            ->where('material_id', $materialId);

        if ($location->is_default && ! $location->isInTransit()) {
            $query->where(function ($inner) use ($locationId): void {
                $inner->where('location_id', $locationId)->orWhereNull('location_id');
            });
        } else {
            $query->where('location_id', $locationId);
        }

        return $this->sumBucket($query);
    }

    private function sumBucket($query): string
    {
        $in = (clone $query)->sum('stock_in');
        $out = (clone $query)->sum('stock_out');

        return Money::sub(
            Money::scale((string) $in, 3) ?? '0.000',
            Money::scale((string) $out, 3) ?? '0.000',
            3
        );
    }

    public function move(
        int $projectId,
        int $materialId,
        string $date,
        string $type,
        string $sourceType,
        int $sourceId,
        int $sourceItemId,
        string $sourceNumber,
        string $stockIn,
        string $stockOut,
        ?int $userId,
        ?string $remarks,
        ?int $locationId = null,
    ): StockLedgerEntry {
        return DB::transaction(function () use ($projectId, $materialId, $date, $type, $sourceType, $sourceId, $sourceItemId, $sourceNumber, $stockIn, $stockOut, $userId, $remarks, $locationId) {
            Material::query()->whereKey($materialId)->lockForUpdate()->firstOrFail();
            StockLedgerEntry::query()
                ->where('project_id', $projectId)
                ->where('material_id', $materialId)
                ->lockForUpdate()
                ->get(['id']);

            $duplicate = StockLedgerEntry::query()
                ->where('source_type', $sourceType)
                ->where('source_item_id', $sourceItemId)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'stock' => 'This stock movement has already been posted.',
                ]);
            }

            $in = Money::scale($stockIn, 3) ?? '0.000';
            $out = Money::scale($stockOut, 3) ?? '0.000';
            $balance = $locationId
                ? $this->locationBalance($projectId, $materialId, $locationId)
                : $this->balance($projectId, $materialId);
            $next = Money::sub(Money::add($balance, $in, 3), $out, 3);

            if (bccomp($next, '0', 3) === -1) {
                throw ValidationException::withMessages([
                    'quantity' => 'Quantity exceeds available stock.',
                ]);
            }

            return StockLedgerEntry::query()->create([
                'project_id' => $projectId,
                'material_id' => $materialId,
                'location_id' => $locationId,
                'transaction_number' => DocumentNumber::next(StockLedgerEntry::class, 'transaction_number', 'STK'),
                'transaction_date' => $date,
                'transaction_type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_item_id' => $sourceItemId,
                'source_number' => $sourceNumber,
                'stock_in' => $in,
                'stock_out' => $out,
                'balance' => $next,
                'posted_by' => $userId,
                'remarks' => $remarks,
            ]);
        });
    }
}
