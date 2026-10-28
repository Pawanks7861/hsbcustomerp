<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\StockLedgerEntry;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStock
{
    public function balance(?int $projectId, int $materialId): string
    {
        return $this->sumBucket(
            $this->forProject(StockLedgerEntry::query(), $projectId)
                ->where('material_id', $materialId)
                ->where(function ($query): void {
                    $query->whereNull('location_id')
                        ->orWhereHas('location', fn ($location) => $location->where('location_type', '!=', InventoryLocation::IN_TRANSIT));
                })
        );
    }

    public function locationBalance(?int $projectId, int $materialId, int $locationId): string
    {
        $location = InventoryLocation::query()->findOrFail($locationId);
        $query = $this->forProject(StockLedgerEntry::query(), $projectId)
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

    /**
     * @param  iterable<InventoryLocation>  $locations
     * @return array{total: array<int, string>, received: array<int, string>, issued: array<int, string>, at: array<int, array<int, string>>}
     */
    public function projectBalances(?int $projectId, iterable $locations): array
    {
        $rows = $this->forProject(StockLedgerEntry::query(), $projectId)
            ->groupBy('material_id', 'location_id', 'transaction_type')
            ->get([
                'material_id',
                'location_id',
                'transaction_type',
                DB::raw('SUM(stock_in) as in_sum'),
                DB::raw('SUM(stock_out) as out_sum'),
            ]);

        $locationIds = $rows->pluck('location_id')->filter()->unique()->values();
        $countable = $locationIds->isEmpty()
            ? []
            : InventoryLocation::query()
                ->whereIn('id', $locationIds)
                ->where('location_type', '!=', InventoryLocation::IN_TRANSIT)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();

        $net = [];
        $result = ['total' => [], 'received' => [], 'issued' => [], 'at' => []];

        foreach ($rows as $row) {
            $materialId = (int) $row->material_id;
            $locationKey = $row->location_id === null ? 0 : (int) $row->location_id;
            $in = Money::scale((string) $row->in_sum, 3) ?? '0.000';
            $out = Money::scale((string) $row->out_sum, 3) ?? '0.000';
            $movement = Money::sub($in, $out, 3);

            $net[$materialId][$locationKey] = Money::add($net[$materialId][$locationKey] ?? '0.000', $movement, 3);

            if ($locationKey === 0 || isset($countable[$locationKey])) {
                $result['total'][$materialId] = Money::add($result['total'][$materialId] ?? '0.000', $movement, 3);
            }
            if ($row->transaction_type === StockLedgerEntry::RECEIPT) {
                $result['received'][$materialId] = Money::add($result['received'][$materialId] ?? '0.000', $in, 3);
            }
            if ($row->transaction_type === StockLedgerEntry::ISSUE) {
                $result['issued'][$materialId] = Money::add($result['issued'][$materialId] ?? '0.000', $out, 3);
            }
        }

        foreach ($net as $materialId => $byLocation) {
            foreach ($locations as $location) {
                $locationId = (int) $location->id;
                $value = $byLocation[$locationId] ?? '0.000';
                if ($location->is_default && ! $location->isInTransit()) {
                    $value = Money::add($value, $byLocation[0] ?? '0.000', 3);
                }
                $result['at'][$materialId][$locationId] = $value;
            }
        }

        return $result;
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
        ?int $projectId,
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
        ?string $unitCost = null,
        bool $estimated = false,
    ): StockLedgerEntry {
        return DB::transaction(function () use ($projectId, $materialId, $date, $type, $sourceType, $sourceId, $sourceItemId, $sourceNumber, $stockIn, $stockOut, $userId, $remarks, $locationId, $unitCost, $estimated) {
            Material::query()->whereKey($materialId)->lockForUpdate()->firstOrFail();
            $this->forProject(StockLedgerEntry::query(), $projectId)
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

            $entry = StockLedgerEntry::query()->create([
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

            app(InventoryValuation::class)->apply($entry, $unitCost, $estimated);

            return $entry->fresh();
        });
    }

    private function forProject(Builder $query, ?int $projectId): Builder
    {
        return $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);
    }
}
