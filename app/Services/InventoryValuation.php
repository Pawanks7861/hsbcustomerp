<?php

namespace App\Services;

use App\Models\InventoryAsset;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\StockCostLayer;
use App\Models\StockCostMovement;
use App\Models\StockLedgerEntry;
use App\Support\MaterialAttributes;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InventoryValuation
{
    public const METHOD = 'fifo';

    /**
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    public function apply(StockLedgerEntry $entry, ?string $explicitUnitCost = null, bool $explicitEstimated = false): array
    {
        $in = Money::scale((string) $entry->stock_in, 3) ?? '0.000';
        $out = Money::scale((string) $entry->stock_out, 3) ?? '0.000';

        if (bccomp($out, '0', 3) === 1) {
            $slices = $entry->transaction_type === StockLedgerEntry::REVERSAL
                ? $this->reverseOutbound($entry, $out)
                : $this->consume($entry, $out);
            $this->stamp($entry, $slices);
            $this->consumeAssets($entry, $slices);

            return $slices;
        }

        if (bccomp($in, '0', 3) !== 1) {
            return [];
        }

        if ($entry->transaction_type === StockLedgerEntry::REVERSAL) {
            $slices = $this->restoreInbound($entry, $in);
            $this->stamp($entry, $slices);
            $this->receiveAssets($entry, $slices, copied: true);

            return $slices;
        }

        $copied = $this->pairedOutboundSlices($entry);
        if ($copied !== []) {
            $slices = $this->receiveSlices($entry, $copied);
            $this->stamp($entry, $slices);
            $this->receiveAssets($entry, $slices, copied: true);

            return $slices;
        }

        $cost = $explicitUnitCost;
        $estimated = $explicitEstimated;
        if ($cost === null) {
            $material = Material::query()->find($entry->material_id);
            $cost = MaterialAttributes::normalizeUnitCost($material?->default_unit_price) ?? '0.00';
            $estimated = true;
        }

        $slices = $this->receive($entry, $in, $cost, $estimated);
        $this->stamp($entry, $slices);
        $this->receiveAssets($entry, $slices, copied: false);

        return $slices;
    }

    /**
     * @param  list<int>  $materialIds
     * @return Collection<int, Collection<int, StockCostLayer>>
     */
    public function remainingLayers(?int $projectId, array $materialIds): Collection
    {
        if ($materialIds === []) {
            return collect();
        }

        $query = StockCostLayer::query()
            ->whereIn('material_id', $materialIds)
            ->where('remaining_qty', '>', 0)
            ->orderBy('id');
        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        return $query->get()->groupBy(fn (StockCostLayer $layer) => (int) $layer->material_id);
    }

    /**
     * @param  iterable<StockCostLayer>  $layers
     * @return Collection<int, StockCostLayer>
     */
    public function layersAt(iterable $layers, ?InventoryLocation $location): Collection
    {
        $collection = collect($layers);
        if (! $location) {
            return $collection->values();
        }

        $locationId = (int) $location->id;

        return $collection->filter(function (StockCostLayer $layer) use ($location, $locationId) {
            if ((int) $layer->location_id === $locationId) {
                return true;
            }

            return $location->is_default && ! $location->isInTransit() && $layer->location_id === null;
        })->values();
    }

    public function onHand(?int $projectId, int $materialId, string $availableQty, ?int $locationId = null): array
    {
        $available = Money::scale($availableQty, 3) ?? '0.000';
        $layers = $this->layerQuery($projectId, $materialId, $locationId)
            ->where('remaining_qty', '>', 0)
            ->orderBy('id')
            ->get();

        return $this->summarize($layers, Material::query()->find($materialId), $available);
    }

    /**
     * @param  iterable<StockCostLayer>  $layers
     * @return array{qty: string, value: string, unit_cost: ?string, is_estimated: bool, unvalued_qty: string, cost_basis: string}
     */
    public function summarize(iterable $layers, ?Material $material, string $availableQty): array
    {
        $available = Money::scale($availableQty, 3) ?? '0.000';
        $layeredQty = '0.000';
        $value = '0.00';
        $estimated = false;
        $hasActual = false;

        foreach ($layers as $layer) {
            $qty = Money::scale((string) $layer->remaining_qty, 3) ?? '0.000';
            $layeredQty = Money::add($layeredQty, $qty, 3);
            $value = Money::add($value, Money::mul($qty, (string) $layer->unit_cost, 2), 2);
            if ($layer->is_estimated) {
                $estimated = true;
            } else {
                $hasActual = true;
            }
        }

        $unvalued = Money::sub($available, $layeredQty, 3);
        if (bccomp($unvalued, '0', 3) === 1) {
            $fallback = MaterialAttributes::normalizeUnitCost($material?->default_unit_price) ?? '0.00';
            $value = Money::add($value, Money::mul($unvalued, $fallback, 2), 2);
            $estimated = true;
        } elseif (bccomp($unvalued, '0', 3) === -1) {
            $unvalued = '0.000';
        }

        $unitCost = bccomp($available, '0', 3) === 1
            ? Money::round(bcdiv($value, $available, 8), 2)
            : null;

        $costBasis = 'Actual';
        if ($estimated && $hasActual) {
            $costBasis = 'Mixed';
        } elseif ($estimated) {
            $costBasis = 'Estimated';
        }

        return [
            'qty' => $available,
            'value' => $value,
            'unit_cost' => $unitCost,
            'is_estimated' => $estimated,
            'unvalued_qty' => $unvalued,
            'cost_basis' => $costBasis,
        ];
    }

    public function consumptionTotal(?int $projectId, ?int $materialId = null): string
    {
        $query = StockLedgerEntry::query()
            ->where('transaction_type', StockLedgerEntry::ISSUE)
            ->whereHas('material', fn ($material) => $material->where('classification', MaterialAttributes::CONSUMABLE));

        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        if ($materialId) {
            $query->where('material_id', $materialId);
        }

        return Money::scale((string) $query->sum('amount'), 2) ?? '0.00';
    }

    /**
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    private function consume(StockLedgerEntry $entry, string $qty): array
    {
        $remaining = $qty;
        $slices = [];
        $layers = $this->layerQuery(
            $entry->project_id !== null ? (int) $entry->project_id : null,
            (int) $entry->material_id,
            $entry->location_id ? (int) $entry->location_id : null,
        )
            ->where('remaining_qty', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', 3) !== 1) {
                break;
            }

            $take = bccomp((string) $layer->remaining_qty, $remaining, 3) === 1
                ? $remaining
                : (Money::scale((string) $layer->remaining_qty, 3) ?? '0.000');

            if (bccomp($take, '0', 3) !== 1) {
                continue;
            }

            $layer->remaining_qty = Money::sub((string) $layer->remaining_qty, $take, 3);
            $layer->save();

            $amount = Money::mul($take, (string) $layer->unit_cost, 2);
            $slices[] = $this->slice($take, (string) $layer->unit_cost, $amount, (bool) $layer->is_estimated, (int) $layer->id);
            $remaining = Money::sub($remaining, $take, 3);
        }

        if (bccomp($remaining, '0', 3) === 1) {
            $material = Material::query()->find($entry->material_id);
            $fallback = MaterialAttributes::normalizeUnitCost($material?->default_unit_price) ?? '0.00';
            $slices[] = $this->slice($remaining, $fallback, Money::mul($remaining, $fallback, 2), true, null);
        }

        $this->writeMovements($entry, $slices);

        return $slices;
    }

    /**
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    private function receive(StockLedgerEntry $entry, string $qty, string $unitCost, bool $estimated): array
    {
        $amount = Money::mul($qty, $unitCost, 2);
        $layer = StockCostLayer::query()->create([
            'project_id' => $entry->project_id,
            'material_id' => $entry->material_id,
            'location_id' => $entry->location_id,
            'remaining_qty' => $qty,
            'unit_cost' => $unitCost,
            'is_estimated' => $estimated,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'source_item_id' => $entry->source_item_id,
            'received_at' => $entry->transaction_date,
        ]);

        $slices = [$this->slice($qty, $unitCost, $amount, $estimated, (int) $layer->id)];
        $this->writeMovements($entry, $slices);

        return $slices;
    }

    /**
     * @param  list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>  $incoming
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    private function receiveSlices(StockLedgerEntry $entry, array $incoming): array
    {
        $slices = [];

        foreach ($incoming as $slice) {
            $layer = StockCostLayer::query()->create([
                'project_id' => $entry->project_id,
                'material_id' => $entry->material_id,
                'location_id' => $entry->location_id,
                'remaining_qty' => $slice['qty'],
                'unit_cost' => $slice['unit_cost'],
                'is_estimated' => $slice['is_estimated'],
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'source_item_id' => $entry->source_item_id,
                'received_at' => $entry->transaction_date,
            ]);
            $slices[] = $this->slice(
                $slice['qty'],
                $slice['unit_cost'],
                $slice['amount'],
                $slice['is_estimated'],
                (int) $layer->id,
                $slice['asset_id'] ?? null,
            );
        }

        $this->writeMovements($entry, $slices);

        return $slices;
    }

    /**
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    private function reverseOutbound(StockLedgerEntry $entry, string $qty): array
    {
        $original = StockLedgerEntry::query()->find($entry->source_item_id);
        if (! $original) {
            return $this->consume($entry, $qty);
        }

        $slices = [];
        foreach ($original->costMovements()->orderBy('id')->get() as $movement) {
            if ($movement->stock_cost_layer_id) {
                $layer = StockCostLayer::query()->whereKey($movement->stock_cost_layer_id)->lockForUpdate()->first();
                if ($layer) {
                    $take = Money::scale((string) $movement->quantity, 3) ?? '0.000';
                    $available = Money::scale((string) $layer->remaining_qty, 3) ?? '0.000';
                    $use = bccomp($available, $take, 3) === -1 ? $available : $take;
                    if (bccomp($use, '0', 3) === 1) {
                        $layer->remaining_qty = Money::sub($available, $use, 3);
                        $layer->save();
                    }
                    if (bccomp($take, $use, 3) === 1) {
                        $take = $use;
                    }
                    $slices[] = $this->slice(
                        Money::scale((string) $movement->quantity, 3) ?? '0.000',
                        (string) $movement->unit_cost,
                        (string) $movement->amount,
                        (bool) $movement->is_estimated,
                        (int) $layer->id,
                        $movement->inventory_asset_id ? (int) $movement->inventory_asset_id : null,
                    );
                    continue;
                }
            }

            $slices[] = $this->slice(
                Money::scale((string) $movement->quantity, 3) ?? '0.000',
                (string) $movement->unit_cost,
                (string) $movement->amount,
                (bool) $movement->is_estimated,
                null,
                $movement->inventory_asset_id ? (int) $movement->inventory_asset_id : null,
            );
        }

        if ($slices === []) {
            return $this->consume($entry, $qty);
        }

        $this->writeMovements($entry, $slices);

        return $slices;
    }

    /**
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    private function restoreInbound(StockLedgerEntry $entry, string $qty): array
    {
        $original = StockLedgerEntry::query()->find($entry->source_item_id);
        if (! $original) {
            $material = Material::query()->find($entry->material_id);
            $cost = MaterialAttributes::normalizeUnitCost($material?->default_unit_price) ?? '0.00';

            return $this->receive($entry, $qty, $cost, true);
        }

        $incoming = [];
        foreach ($original->costMovements()->orderBy('id')->get() as $movement) {
            $incoming[] = $this->slice(
                Money::scale((string) $movement->quantity, 3) ?? '0.000',
                (string) $movement->unit_cost,
                (string) $movement->amount,
                (bool) $movement->is_estimated,
                null,
                $movement->inventory_asset_id ? (int) $movement->inventory_asset_id : null,
            );
        }

        if ($incoming === []) {
            $cost = (string) ($original->unit_cost ?? '0.00');

            return $this->receive($entry, $qty, $cost, (bool) $original->is_estimated);
        }

        return $this->receiveSlices($entry, $incoming);
    }

    /**
     * @return list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>
     */
    private function pairedOutboundSlices(StockLedgerEntry $entry): array
    {
        $outbound = StockLedgerEntry::query()
            ->where('source_id', $entry->source_id)
            ->where('source_item_id', $entry->source_item_id)
            ->where('id', '<', $entry->id)
            ->where('stock_out', '>', 0)
            ->orderByDesc('id')
            ->first();

        if (! $outbound) {
            return [];
        }

        $slices = [];
        foreach ($outbound->costMovements()->orderBy('id')->get() as $movement) {
            $slices[] = $this->slice(
                Money::scale((string) $movement->quantity, 3) ?? '0.000',
                (string) $movement->unit_cost,
                (string) $movement->amount,
                (bool) $movement->is_estimated,
                null,
                $movement->inventory_asset_id ? (int) $movement->inventory_asset_id : null,
            );
        }

        if ($slices === [] && $outbound->unit_cost !== null) {
            $qty = Money::scale((string) $outbound->stock_out, 3) ?? '0.000';
            $slices[] = $this->slice(
                $qty,
                (string) $outbound->unit_cost,
                (string) ($outbound->amount ?? Money::mul($qty, (string) $outbound->unit_cost, 2)),
                (bool) $outbound->is_estimated,
                null,
            );
        }

        return $slices;
    }

    /**
     * @param  list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>  $slices
     */
    private function stamp(StockLedgerEntry $entry, array $slices): void
    {
        $qty = '0.000';
        $amount = '0.00';
        $estimated = false;

        foreach ($slices as $slice) {
            $qty = Money::add($qty, $slice['qty'], 3);
            $amount = Money::add($amount, $slice['amount'], 2);
            $estimated = $estimated || $slice['is_estimated'];
        }

        $unitCost = bccomp($qty, '0', 3) === 1
            ? Money::round(bcdiv($amount, $qty, 8), 2)
            : '0.00';

        $entry->forceFill([
            'unit_cost' => $unitCost,
            'amount' => $amount,
            'is_estimated' => $estimated,
        ])->save();
    }

    /**
     * @param  list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>  $slices
     */
    private function writeMovements(StockLedgerEntry $entry, array $slices): void
    {
        foreach ($slices as $slice) {
            StockCostMovement::query()->create([
                'stock_ledger_entry_id' => $entry->id,
                'stock_cost_layer_id' => $slice['layer_id'],
                'inventory_asset_id' => $slice['asset_id'],
                'quantity' => $slice['qty'],
                'unit_cost' => $slice['unit_cost'],
                'amount' => $slice['amount'],
                'is_estimated' => $slice['is_estimated'],
            ]);
        }
    }

    /**
     * @param  list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>  $slices
     */
    private function receiveAssets(StockLedgerEntry $entry, array $slices, bool $copied): void
    {
        $material = Material::query()->find($entry->material_id);
        if (! $material || $material->classification !== MaterialAttributes::ASSET) {
            return;
        }

        $status = match ($entry->transaction_type) {
            StockLedgerEntry::IN_TRANSIT => InventoryAsset::IN_TRANSIT,
            StockLedgerEntry::ISSUE => InventoryAsset::ASSIGNED,
            default => InventoryAsset::IN_STORE,
        };

        foreach ($slices as $index => $slice) {
            $parentId = $copied ? ($slice['asset_id'] ?? null) : null;
            $asset = InventoryAsset::query()->create([
                'asset_code' => DocumentNumber::next(InventoryAsset::class, 'asset_code', 'AST'),
                'material_id' => $entry->material_id,
                'parent_id' => $parentId,
                'quantity' => $slice['qty'],
                'remaining_quantity' => $slice['qty'],
                'unit_cost' => $slice['unit_cost'],
                'acquisition_cost' => $parentId ? '0.00' : $slice['amount'],
                'useful_life_years' => $material->useful_life_years,
                'project_id' => $entry->project_id,
                'location_id' => $entry->location_id,
                'status' => $status,
                'acquired_on' => $entry->transaction_date,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'source_item_id' => $entry->source_item_id,
                'stock_cost_layer_id' => $slice['layer_id'],
            ]);

            $movement = $entry->costMovements()->orderBy('id')->skip($index)->first();
            if ($movement) {
                $movement->update(['inventory_asset_id' => $asset->id]);
            }
        }
    }

    /**
     * @param  list<array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}>  $slices
     */
    private function consumeAssets(StockLedgerEntry $entry, array $slices): void
    {
        $material = Material::query()->find($entry->material_id);
        if (! $material || $material->classification !== MaterialAttributes::ASSET) {
            return;
        }

        $need = '0.000';
        foreach ($slices as $slice) {
            $need = Money::add($need, $slice['qty'], 3);
        }

        $assets = $this->assetQuery(
            $entry->project_id !== null ? (int) $entry->project_id : null,
            (int) $entry->material_id,
            $entry->location_id ? (int) $entry->location_id : null,
        )
            ->where('remaining_quantity', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $consumed = [];
        foreach ($assets as $asset) {
            if (bccomp($need, '0', 3) !== 1) {
                break;
            }

            $available = Money::scale((string) $asset->remaining_quantity, 3) ?? '0.000';
            $take = bccomp($available, $need, 3) === 1 ? $need : $available;
            $asset->remaining_quantity = Money::sub($available, $take, 3);
            if (bccomp((string) $asset->remaining_quantity, '0', 3) !== 1) {
                $asset->status = $entry->transaction_type === StockLedgerEntry::ISSUE
                    ? InventoryAsset::ASSIGNED
                    : InventoryAsset::TRANSFERRED;
            }
            $asset->save();
            $consumed[] = ['asset' => $asset, 'qty' => $take];
            $need = Money::sub($need, $take, 3);
        }

        $offset = 0;
        foreach ($entry->costMovements()->orderBy('id')->get() as $movement) {
            $match = $consumed[$offset] ?? null;
            if ($match) {
                $movement->update(['inventory_asset_id' => $match['asset']->originalId()]);
                $offset++;
            }
        }
    }

    private function layerQuery(?int $projectId, int $materialId, ?int $locationId): Builder
    {
        $query = StockCostLayer::query()->where('material_id', $materialId);
        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        if ($locationId) {
            $location = InventoryLocation::query()->find($locationId);
            if ($location && $location->is_default && ! $location->isInTransit()) {
                $query->where(function ($inner) use ($locationId): void {
                    $inner->where('location_id', $locationId)->orWhereNull('location_id');
                });
            } else {
                $query->where('location_id', $locationId);
            }
        }

        return $query;
    }

    private function assetQuery(?int $projectId, int $materialId, ?int $locationId): Builder
    {
        $query = InventoryAsset::query()->where('material_id', $materialId);
        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        if ($locationId) {
            $location = InventoryLocation::query()->find($locationId);
            if ($location && $location->is_default && ! $location->isInTransit()) {
                $query->where(function ($inner) use ($locationId): void {
                    $inner->where('location_id', $locationId)->orWhereNull('location_id');
                });
            } else {
                $query->where('location_id', $locationId);
            }
        }

        return $query;
    }

    /**
     * @return array{qty: string, unit_cost: string, amount: string, is_estimated: bool, layer_id: ?int, asset_id: ?int}
     */
    private function slice(string $qty, string $unitCost, string $amount, bool $estimated, ?int $layerId, ?int $assetId = null): array
    {
        return [
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => $amount,
            'is_estimated' => $estimated,
            'layer_id' => $layerId,
            'asset_id' => $assetId,
        ];
    }
}
