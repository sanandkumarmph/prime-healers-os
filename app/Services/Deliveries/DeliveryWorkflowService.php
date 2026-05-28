<?php

namespace App\Services\Deliveries;

use App\Models\Asset;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementRecorder;
use Illuminate\Support\Facades\DB;

class DeliveryWorkflowService
{
    private function recordMovement(array $attributes): void
    {
        app(StockMovementRecorder::class)->record($attributes);
    }

    public function ensureRentalItemsExist(int $organizationId, Rental $rental): void
    {
        if (!Rental::hasRentalItemsTable()) {
            return;
        }

        if ($rental->relationLoaded('rentalItems') ? $rental->rentalItems->isNotEmpty() : $rental->rentalItems()->exists()) {
            return;
        }

        $quantity = max((int) ($rental->quantity ?? 0), 1);
        $lineTotal = (float) ($rental->rental_amount ?? 0);

        $rental->rentalItems()->create([
            'organization_id' => $organizationId,
            'product_id' => $rental->product_id,
            'quantity' => $quantity,
            'ordered_quantity' => $quantity,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => $quantity > 0 ? round($lineTotal / $quantity, 2) : $lineTotal,
            'line_total' => $lineTotal,
            'notes' => 'Backfilled from legacy rental line.',
        ]);

        $rental->unsetRelation('rentalItems');
    }

    public function loadRentalProgressItems(int $organizationId, Rental $rental)
    {
        $this->ensureRentalItemsExist($organizationId, $rental);

        return $rental->rentalItems()->with('product')->orderBy('id')->get();
    }

    public function nextPendingAssetIdsForDelivery(RentalItem $item, int $quantity): array
    {
        $assetIds = collect($item->asset_ids ?? [])
            ->filter(fn ($assetId) => filled($assetId))
            ->map(fn ($assetId) => (int) $assetId)
            ->values();

        if ($assetIds->isEmpty()) {
            return [];
        }

        return $assetIds
            ->slice($item->delivered_quantity_value, $quantity)
            ->values()
            ->all();
    }

    public function nextDeliveredAssetIdsForReturn(RentalItem $item, int $quantity): array
    {
        $assetIds = collect($item->asset_ids ?? [])
            ->filter(fn ($assetId) => filled($assetId))
            ->map(fn ($assetId) => (int) $assetId)
            ->values();

        if ($assetIds->isEmpty()) {
            return [];
        }

        return $assetIds
            ->slice($item->returned_quantity_value, $quantity)
            ->values()
            ->all();
    }

    public function releaseSpecificRentalAssets(int $organizationId, Rental $rental, array $assetIds, ?string $returnedAt = null): void
    {
        if (!\App\Models\RentalAsset::hasTable() || empty($assetIds)) {
            return;
        }

        $timestamp = $returnedAt ?: now();

        $assignments = $rental->activeRentalAssets()
            ->with('asset')
            ->whereIn('asset_id', $assetIds)
            ->get();

        foreach ($assignments as $assignment) {
            $asset = $assignment->asset;

            if (!$asset) {
                continue;
            }

            $assignment->update([
                'returned_at' => $timestamp,
                'return_condition' => $asset->condition_status,
                'notes' => trim(($assignment->notes ? $assignment->notes . ' | ' : '') . 'Released on partial pickup and awaiting verification.'),
            ]);

            $asset->update(['asset_status' => Asset::STATUS_AWAITING_VERIFICATION]);
        }
    }

    public function restoreLegacyRentalItemStock(int $organizationId, RentalItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->find($item->product_id);

        if (!$product?->usesUntrackedStock()) {
            return;
        }

        Product::query()
            ->where('organization_id', $organizationId)
            ->where('id', $item->product_id)
            ->increment('available_quantity', $quantity);

        $this->recordMovement([
            'organization_id' => $organizationId,
            'product_id' => $item->product_id,
            'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
            'quantity' => $quantity,
            'from_status' => 'with_customer',
            'to_status' => 'available',
            'rental_id' => $item->rental_id ?? null,
            'performed_by_user_id' => auth()->id(),
            'notes' => 'Pickup return restored legacy untracked rental stock.',
        ]);
    }

    public function syncRentalProgressAssetStatuses(int $organizationId, Rental $rental): void
    {
        if (!\App\Models\RentalAsset::hasTable() || !Rental::hasRentalItemsTable()) {
            return;
        }

        $items = $this->loadRentalProgressItems($organizationId, $rental);

        if ($items->isEmpty()) {
            return;
        }

        $rentedIds = [];
        $reservedIds = [];

        foreach ($items as $item) {
            $assetIds = collect($item->asset_ids ?? [])
                ->filter(fn ($assetId) => filled($assetId))
                ->map(fn ($assetId) => (int) $assetId)
                ->values();

            if ($assetIds->isEmpty()) {
                continue;
            }

            $deliveredActiveCount = max($item->delivered_quantity_value - $item->returned_quantity_value, 0);

            $rentedIds = array_merge($rentedIds, $assetIds->slice($item->returned_quantity_value, $deliveredActiveCount)->all());
            $reservedIds = array_merge($reservedIds, $assetIds->slice($item->delivered_quantity_value)->all());
        }

        $rentedIds = array_values(array_unique(array_map('intval', $rentedIds)));
        $reservedIds = array_values(array_diff(array_unique(array_map('intval', $reservedIds)), $rentedIds));

        if ($rentedIds !== []) {
            Asset::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $rentedIds)
                ->update(['asset_status' => 'rented']);
        }

        if ($reservedIds !== []) {
            Asset::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $reservedIds)
                ->update(['asset_status' => 'reserved']);
        }
    }

    public function syncAssignedAssetStatuses(int $organizationId, Delivery $delivery, bool $hasSaleColumn): void
    {
        if ($hasSaleColumn && $delivery->sale_id) {
            $sale = $delivery->sale()->with('asset')->first();

            if ($sale?->asset_id) {
                if ($delivery->status === 'completed') {
                    Asset::where('organization_id', $organizationId)
                        ->where('id', $sale->asset_id)
                        ->update(['asset_status' => 'sold']);
                } elseif ($delivery->status === 'in_progress' && $sale->asset?->asset_status === 'available_for_sale') {
                    Asset::where('organization_id', $organizationId)
                        ->where('id', $sale->asset_id)
                        ->update(['asset_status' => 'reserved']);
                }
            }
        }

        if (!\App\Models\RentalAsset::hasTable()) {
            return;
        }

        $rental = $delivery->rental()->with('activeRentalAssets')->first();

        if (!$rental) {
            return;
        }

        $assetIds = $rental->activeRentalAssets->pluck('asset_id')->all();

        if ($assetIds === []) {
            return;
        }

        if (Rental::hasRentalItemsTable()) {
            $this->syncRentalProgressAssetStatuses($organizationId, $rental);
        } else {
            if ($delivery->type === 'delivery' && $delivery->status === 'completed') {
                Asset::where('organization_id', $organizationId)
                    ->whereIn('id', $assetIds)
                    ->update(['asset_status' => 'rented']);
            }

            if ($delivery->type === 'delivery' && $delivery->status === 'in_progress') {
                Asset::where('organization_id', $organizationId)
                    ->whereIn('id', $assetIds)
                    ->update(['asset_status' => 'reserved']);
            }
        }

        if (!Rental::hasSaleItemsTable()) {
            return;
        }

        $rental->loadMissing('saleItems.asset');
        $saleAssetIds = $rental->saleItems
            ->pluck('asset_id')
            ->filter(fn ($assetId) => filled($assetId))
            ->map(fn ($assetId) => (int) $assetId)
            ->unique()
            ->values()
            ->all();

        if ($saleAssetIds === [] || $delivery->type !== 'delivery') {
            return;
        }

        if ($delivery->status === 'completed') {
            Asset::where('organization_id', $organizationId)
                ->whereIn('id', $saleAssetIds)
                ->update(['asset_status' => 'sold']);
        } elseif ($delivery->status === 'in_progress') {
            Asset::where('organization_id', $organizationId)
                ->whereIn('id', $saleAssetIds)
                ->where('asset_status', 'available_for_sale')
                ->update(['asset_status' => 'reserved']);
        }
    }

    public function releaseRentalAssetsForReturn(int $organizationId, Rental $rental, ?string $returnedAt = null): void
    {
        if (!\App\Models\RentalAsset::hasTable()) {
            return;
        }

        $timestamp = $returnedAt ?: now();

        $activeAssignments = $rental->activeRentalAssets()
            ->with('asset')
            ->get();

        foreach ($activeAssignments as $assignment) {
            $asset = $assignment->asset;

            if (!$asset) {
                continue;
            }

            $assignment->update([
                'returned_at' => $timestamp,
                'return_condition' => $asset->condition_status,
                'notes' => trim(($assignment->notes ? $assignment->notes . ' | ' : '') . 'Released on pickup completion and awaiting verification.'),
            ]);

            $asset->update(['asset_status' => Asset::STATUS_AWAITING_VERIFICATION]);
        }
    }

    public function completePickupReturn(int $organizationId, Rental $rental, ?Delivery $pickup = null, ?string $returnedAt = null): Delivery
    {
        $timestamp = $returnedAt ?: now()->toDateTimeString();
        $pickup ??= $rental->pickupRecord()->first();

        if ($pickup) {
            $pickup->forceFill([
                'status' => 'completed',
                'completed_at' => $timestamp,
                'scheduled_at' => $pickup->scheduled_at ?: $timestamp,
            ])->save();
        } else {
            $pickup = Delivery::create([
                'organization_id' => $organizationId,
                'rental_id' => $rental->id,
                'type' => 'pickup',
                'scheduled_at' => $timestamp,
                'status' => 'completed',
                'completed_at' => $timestamp,
                'notes' => 'Auto-completed when rental was marked returned.',
            ]);
        }

        $this->finalizePickupCompletionForRental($organizationId, $rental->fresh(), $timestamp, $pickup->id);

        return $pickup->fresh();
    }

    public function finalizePickupCompletionForRental(int $organizationId, Rental $rental, ?string $returnedAt = null, ?int $deliveryId = null): void
    {
        $timestamp = $returnedAt ?: now()->toDateTimeString();
        $processedAssetIds = [];

        if (!Rental::hasRentalItemsTable()) {
            $activeAssignments = \App\Models\RentalAsset::hasTable()
                ? $rental->activeRentalAssets()->with('asset')->get()
                : collect();

            foreach ($activeAssignments as $assignment) {
                $asset = $assignment->asset;

                if (!$asset) {
                    continue;
                }

                $assignment->update([
                    'returned_at' => $timestamp,
                    'return_condition' => $asset->condition_status,
                    'notes' => trim(($assignment->notes ? $assignment->notes . ' | ' : '') . 'Released on pickup completion and awaiting verification.'),
                ]);

                $asset->update(['asset_status' => Asset::STATUS_AWAITING_VERIFICATION]);

                $this->recordMovement([
                    'organization_id' => $organizationId,
                    'product_id' => $asset->product_id ?: $rental->product_id,
                    'asset_id' => $asset->id,
                    'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
                    'quantity' => 1,
                    'from_status' => 'rented',
                    'to_status' => Asset::STATUS_AWAITING_VERIFICATION,
                    'from_warehouse_id' => $rental->dispatch_warehouse_id,
                    'to_warehouse_id' => $rental->dispatch_warehouse_id,
                    'rental_id' => $rental->id,
                    'delivery_id' => $deliveryId,
                    'performed_by_user_id' => auth()->id(),
                    'notes' => 'Rental asset picked up and awaiting verification on workflow completion.',
                ]);
            }

            if ($rental->status !== 'returned') {
                $rental->update([
                    'status' => 'returned',
                    'returned_at' => $timestamp,
                ]);
            }

            return;
        }

        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $rental->rentalItems()->pluck('product_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($this->loadRentalProgressItems($organizationId, $rental) as $item) {
            $outstandingQuantity = max((int) $item->delivered_quantity_value - (int) $item->returned_quantity_value, 0);

            if ($outstandingQuantity <= 0) {
                continue;
            }

            $assetIds = $this->nextDeliveredAssetIdsForReturn($item, $outstandingQuantity);
            $product = $products->get((int) $item->product_id);

            $item->update([
                'returned_quantity' => min($item->delivered_quantity_value, $item->returned_quantity_value + $outstandingQuantity),
            ]);

            if ($assetIds !== []) {
                $this->releaseSpecificRentalAssets($organizationId, $rental, $assetIds, $timestamp);
                $processedAssetIds = array_values(array_unique(array_merge($processedAssetIds, $assetIds)));

                foreach ($assetIds as $assetId) {
                    $this->recordMovement([
                        'organization_id' => $organizationId,
                        'product_id' => $item->product_id,
                        'asset_id' => $assetId,
                        'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
                        'quantity' => 1,
                        'from_status' => 'rented',
                        'to_status' => Asset::STATUS_AWAITING_VERIFICATION,
                        'from_warehouse_id' => $rental->dispatch_warehouse_id,
                        'to_warehouse_id' => $rental->dispatch_warehouse_id,
                        'rental_id' => $rental->id,
                        'delivery_id' => $deliveryId,
                        'performed_by_user_id' => auth()->id(),
                        'notes' => 'Rental asset picked up and awaiting verification on workflow completion.',
                    ]);
                }
            } elseif ($product?->usesUntrackedStock()) {
                $this->restoreLegacyRentalItemStock($organizationId, $item, $outstandingQuantity);
            }
        }

        $rental->unsetRelation('rentalItems');

        if (\App\Models\RentalAsset::hasTable() && $rental->activeRentalAssets()->exists()) {
            $remainingAssignments = $rental->activeRentalAssets()
                ->with('asset')
                ->when($processedAssetIds !== [], fn ($query) => $query->whereNotIn('asset_id', $processedAssetIds))
                ->get();

            foreach ($remainingAssignments as $assignment) {
                $asset = $assignment->asset;

                if (!$asset) {
                    continue;
                }

                $assignment->update([
                    'returned_at' => $timestamp,
                    'return_condition' => $asset->condition_status,
                    'notes' => trim(($assignment->notes ? $assignment->notes . ' | ' : '') . 'Released on pickup completion and awaiting verification.'),
                ]);

                $asset->update(['asset_status' => Asset::STATUS_AWAITING_VERIFICATION]);

                $this->recordMovement([
                    'organization_id' => $organizationId,
                    'product_id' => $asset->product_id ?: $rental->product_id,
                    'asset_id' => $asset->id,
                    'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
                    'quantity' => 1,
                    'from_status' => 'rented',
                    'to_status' => Asset::STATUS_AWAITING_VERIFICATION,
                    'from_warehouse_id' => $rental->dispatch_warehouse_id,
                    'to_warehouse_id' => $rental->dispatch_warehouse_id,
                    'rental_id' => $rental->id,
                    'delivery_id' => $deliveryId,
                    'performed_by_user_id' => auth()->id(),
                    'notes' => 'Rental asset picked up and awaiting verification on workflow completion.',
                ]);
            }
        }

        if ($rental->deliveredQuantityTotal() > 0 && $rental->pendingPickupQuantityTotal() === 0) {
            $rental->update([
                'status' => 'returned',
                'returned_at' => $timestamp,
            ]);
        }
    }

    public function finalizeDeliveryCompletionForRental(int $organizationId, Rental $rental): void
    {
        if (!Rental::hasRentalItemsTable()) {
            return;
        }

        foreach ($this->loadRentalProgressItems($organizationId, $rental) as $item) {
            $outstandingQuantity = max((int) $item->ordered_quantity - (int) $item->delivered_quantity_value, 0);

            if ($outstandingQuantity <= 0) {
                continue;
            }

             $assetIds = $this->nextPendingAssetIdsForDelivery($item, $outstandingQuantity);

            $item->update([
                'delivered_quantity' => min($item->ordered_quantity, $item->delivered_quantity_value + $outstandingQuantity),
            ]);

            foreach ($assetIds as $assetId) {
                $this->recordMovement([
                    'organization_id' => $organizationId,
                    'product_id' => $item->product_id,
                    'asset_id' => $assetId,
                    'movement_type' => StockMovement::TYPE_DELIVERY,
                    'quantity' => 1,
                    'from_status' => 'reserved',
                    'to_status' => 'rented',
                    'from_warehouse_id' => $rental->dispatch_warehouse_id,
                    'to_warehouse_id' => $rental->dispatch_warehouse_id,
                    'rental_id' => $rental->id,
                    'performed_by_user_id' => auth()->id(),
                    'notes' => 'Rental asset delivered on workflow completion.',
                ]);
            }
        }

        $rental->unsetRelation('rentalItems');
    }

    public function reopenDeliveryForRental(int $organizationId, Rental $rental): void
    {
        if (!Rental::hasRentalItemsTable()) {
            return;
        }

        foreach ($this->loadRentalProgressItems($organizationId, $rental) as $item) {
            if ((int) $item->delivered_quantity_value <= 0 && (int) $item->returned_quantity_value <= 0) {
                continue;
            }

            $item->update([
                'delivered_quantity' => 0,
                'returned_quantity' => 0,
            ]);
        }

        $rental->unsetRelation('rentalItems');
    }

    public function recordPartialDelivery(
        int $organizationId,
        Delivery $delivery,
        Rental $rental,
        RentalItem $item,
        int $quantity,
        array $assetIds,
        ?string $notes,
        callable $syncAssignedAssetStatuses,
        callable $logActivity
    ): void {
        DB::transaction(function () use (
            $organizationId,
            $delivery,
            $rental,
            $item,
            $quantity,
            $assetIds,
            $notes,
            $syncAssignedAssetStatuses,
            $logActivity
        ) {
            $item->update([
                'delivered_quantity' => min($item->ordered_quantity, $item->delivered_quantity_value + $quantity),
            ]);

            $rental->unsetRelation('rentalItems');

            if ($delivery->status === 'pending') {
                $delivery->update(['status' => 'in_progress']);
            }

            if (!empty($assetIds)) {
                Asset::query()
                    ->where('organization_id', $organizationId)
                    ->whereIn('id', $assetIds)
                    ->update(['asset_status' => 'rented']);

                foreach ($assetIds as $assetId) {
                    $this->recordMovement([
                        'organization_id' => $organizationId,
                        'product_id' => $item->product_id,
                        'asset_id' => $assetId,
                        'movement_type' => StockMovement::TYPE_DELIVERY,
                        'quantity' => 1,
                        'from_status' => 'reserved',
                        'to_status' => 'rented',
                        'from_warehouse_id' => $rental->dispatch_warehouse_id,
                        'to_warehouse_id' => $rental->dispatch_warehouse_id,
                        'rental_id' => $rental->id,
                        'delivery_id' => $delivery->id,
                        'performed_by_user_id' => auth()->id(),
                        'notes' => 'Rental asset delivered to customer.',
                    ]);
                }
            }

            $syncAssignedAssetStatuses($delivery->fresh());

            $logActivity($delivery, [
                'rental_item_id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => $quantity,
                'notes' => $notes,
            ]);
        });
    }

    public function recordPartialPickup(
        int $organizationId,
        Delivery $delivery,
        Rental $rental,
        RentalItem $item,
        int $quantity,
        array $assetIds,
        ?string $notes,
        callable $syncAssignedAssetStatuses
    ): void {
        DB::transaction(function () use (
            $organizationId,
            $delivery,
            $rental,
            $item,
            $quantity,
            $assetIds,
            $notes,
            $syncAssignedAssetStatuses
        ) {
            $item->update([
                'returned_quantity' => min($item->delivered_quantity_value, $item->returned_quantity_value + $quantity),
            ]);

            $rental->unsetRelation('rentalItems');

            if ($delivery->status === 'pending') {
                $delivery->update(['status' => 'in_progress']);
            }

            if (!empty($assetIds)) {
                $this->releaseSpecificRentalAssets($organizationId, $rental, $assetIds, now()->toDateTimeString());

                foreach ($assetIds as $assetId) {
                    $this->recordMovement([
                        'organization_id' => $organizationId,
                        'product_id' => $item->product_id,
                        'asset_id' => $assetId,
                        'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
                        'quantity' => 1,
                        'from_status' => 'rented',
                        'to_status' => Asset::STATUS_AWAITING_VERIFICATION,
                        'from_warehouse_id' => $rental->dispatch_warehouse_id,
                        'to_warehouse_id' => $rental->dispatch_warehouse_id,
                        'rental_id' => $rental->id,
                        'delivery_id' => $delivery->id,
                        'performed_by_user_id' => auth()->id(),
                        'notes' => 'Rental asset picked up and awaiting verification.',
                    ]);
                }
            } else {
                $this->restoreLegacyRentalItemStock($organizationId, $item, $quantity);
            }

            if ($rental->pendingPickupQuantityTotal() === 0 && $rental->deliveredQuantityTotal() > 0) {
                $rental->update([
                    'status' => 'returned',
                    'returned_at' => now(),
                ]);
            }

            $syncAssignedAssetStatuses($delivery->fresh());
        });
    }

    public function markInProgress(Delivery $delivery, callable $syncAssignedAssetStatuses): void
    {
        $delivery->update([
            'status' => 'in_progress',
        ]);

        $syncAssignedAssetStatuses($delivery);
    }

    public function cancelOlderDuplicateTasks(int $organizationId, Delivery $delivery): void
    {
        $query = Delivery::query()
            ->where('organization_id', $organizationId)
            ->where('type', $delivery->type)
            ->where('id', '!=', $delivery->id)
            ->whereIn('status', ['pending', 'in_progress']);

        if ($delivery->sale_id) {
            $query->where('sale_id', $delivery->sale_id);
        } else {
            $query->where('rental_id', $delivery->rental_id);
        }

        $query->update([
            'status' => 'cancelled',
            'completed_at' => null,
        ]);
    }

    public function markCompleted(
        int $organizationId,
        Delivery $delivery,
        ?Rental $rental,
        callable $syncAssignedAssetStatuses
    ): void {
        $delivery->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->cancelOlderDuplicateTasks($organizationId, $delivery);

        if ($rental) {
            if ($delivery->type === 'pickup') {
                $this->finalizePickupCompletionForRental($organizationId, $rental, optional($delivery->completed_at)->toDateTimeString());
            } elseif ($delivery->type === 'delivery') {
                $this->finalizeDeliveryCompletionForRental($organizationId, $rental);
            }
        }

        $syncAssignedAssetStatuses($delivery);
    }
}
