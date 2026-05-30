<?php

namespace App\Services\Inventory;

use App\Models\Asset;
use App\Models\Rental;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AssetStateService
{
    public function rentalSummary(int $organizationId): array
    {
        return [
            'rentalAssets' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->count(),
            'rentalAvailable' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->rentalReady()
                ->count(),
            'maintenanceAlerts' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->where('asset_status', Asset::STATUS_MAINTENANCE)
                ->count(),
        ];
    }

    public function rentalReconciliation(int $organizationId, Builder $baseAssetsQuery, array $filters): array
    {
        $rentalAssetsQuery = (clone $baseAssetsQuery)->where('asset_stage', Asset::STAGE_RENTAL_STOCK);
        $rentalAssets = (clone $rentalAssetsQuery)
            ->with(['product:id,name', 'warehouse:id,name', 'activeRentalAssignments.rental', 'stockMovements'])
            ->get();

        $availableIds = (clone $rentalAssetsQuery)->rentalReady()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $available = count($availableIds);
        $maintenance = (int) (clone $rentalAssetsQuery)->where('asset_status', Asset::STATUS_MAINTENANCE)->count();
        $awaitingVerification = (int) (clone $rentalAssetsQuery)->where('asset_status', Asset::STATUS_AWAITING_VERIFICATION)->count();
        $reserved = (int) (clone $rentalAssetsQuery)->where('asset_status', Asset::STATUS_RESERVED)->count();
        $transferInProgress = (int) (clone $rentalAssetsQuery)->where('asset_status', 'transfer_in_progress')->count();
        $retired = (int) (clone $rentalAssetsQuery)->where('asset_status', Asset::STATUS_RETIRED)->count();
        $totalRentalAssets = (int) (clone $rentalAssetsQuery)->count();
        $activeRented = $this->canonicalActiveRentedQuantity($organizationId, $filters, $rentalAssets);

        $rentalUnavailableNow = max($totalRentalAssets - $available, 0);
        $explainedUnavailable = $activeRented + $maintenance + $awaitingVerification + $reserved + $transferInProgress + $retired;
        $unclassifiedAssets = $this->rentalUnclassifiedAssets(
            $organizationId,
            $filters,
            $rentalAssets,
            $availableIds,
            $activeRented
        );
        $staleThresholdDays = 7;
        $orphanedRentedAssets = $unclassifiedAssets
            ->filter(fn (array $asset) => ($asset['reason_code'] ?? '') === 'stale_rented')
            ->count();
        $staleStatesCount = $unclassifiedAssets
            ->filter(fn (array $asset) => (int) ($asset['unclassified_for_days'] ?? 0) >= $staleThresholdDays)
            ->count();
        $fullyReconciled = max($totalRentalAssets - $unclassifiedAssets->count(), 0);
        $classifiedRatio = $totalRentalAssets > 0
            ? ($fullyReconciled / $totalRentalAssets) * 100
            : 100.0;
        $healthScore = (int) max(0, min(
            100,
            round($classifiedRatio - min($staleStatesCount * 2, 15) - min($orphanedRentedAssets * 2, 15))
        ));

        return [
            'total_rental_assets' => $totalRentalAssets,
            'available' => $available,
            'active_rented' => $activeRented,
            'maintenance' => $maintenance,
            'awaiting_verification' => $awaitingVerification,
            'reserved' => $reserved,
            'transfer_in_progress' => $transferInProgress,
            'retired' => $retired,
            'rental_unavailable_now' => $rentalUnavailableNow,
            'explained_unavailable' => $explainedUnavailable,
            'unaccounted' => $unclassifiedAssets->count(),
            'unclassified_assets' => $unclassifiedAssets->values()->all(),
            'health_score' => $healthScore,
            'reconciliation_summary' => [
                'total_rental_assets' => $totalRentalAssets,
                'fully_reconciled_assets' => $fullyReconciled,
                'needs_reconciliation_assets' => $unclassifiedAssets->count(),
                'stale_states_count' => $staleStatesCount,
                'orphaned_rented_assets' => $orphanedRentedAssets,
                'stale_threshold_days' => $staleThresholdDays,
            ],
        ];
    }

    public function warehouseRentalReconciliation(int $organizationId, Builder $baseAssetsQuery, array $filters): Collection
    {
        return (clone $baseAssetsQuery)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->with('warehouse:id,name,city')
            ->get()
            ->groupBy(fn (Asset $asset) => (int) ($asset->warehouse_id ?? 0))
            ->map(function (Collection $group, $warehouseId) use ($organizationId, $filters, $baseAssetsQuery) {
                /** @var Asset|null $sample */
                $sample = $group->first();
                $rowFilters = array_merge($filters, ['warehouse_id' => (int) $warehouseId]);
                $rowBaseQuery = clone $baseAssetsQuery;
                $rowBaseQuery->where('warehouse_id', (int) $warehouseId);
                $reconciliation = $this->rentalReconciliation($organizationId, $rowBaseQuery, $rowFilters);

                return array_merge($reconciliation, [
                    'warehouse_id' => (int) $warehouseId,
                    'warehouse_name' => $sample?->warehouse?->name ?: 'Unassigned',
                    'city' => $sample?->warehouse?->city ?: null,
                    'state_sum' => $reconciliation['available']
                        + $reconciliation['active_rented']
                        + $reconciliation['maintenance']
                        + $reconciliation['awaiting_verification']
                        + $reconciliation['reserved']
                        + $reconciliation['transfer_in_progress']
                        + $reconciliation['retired']
                        + $reconciliation['unaccounted'],
                    'reconciles' => (
                        $reconciliation['available']
                        + $reconciliation['active_rented']
                        + $reconciliation['maintenance']
                        + $reconciliation['awaiting_verification']
                        + $reconciliation['reserved']
                        + $reconciliation['transfer_in_progress']
                        + $reconciliation['retired']
                        + $reconciliation['unaccounted']
                    ) === $reconciliation['total_rental_assets'],
                ]);
            })
            ->sortBy('warehouse_name')
            ->values();
    }

    public function canonicalActiveRentedQuantity(int $organizationId, array $filters, ?Collection $rentalAssets = null): int
    {
        $operationalQuantity = $this->operationalActiveRentedQuantity($organizationId, $filters);

        if ($rentalAssets instanceof Collection) {
            $rentedAssetsCount = (int) $rentalAssets
                ->where('asset_status', Asset::STATUS_RENTED)
                ->count();

            return min($operationalQuantity, $rentedAssetsCount);
        }

        return $operationalQuantity;
    }

    private function operationalActiveRentedQuantity(int $organizationId, array $filters): int
    {
        $query = Rental::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->lifecycleStarted()
            ->with(['rentalItems:id,rental_id,product_id,quantity,ordered_quantity,delivered_quantity,returned_quantity']);

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $query->where('dispatch_warehouse_id', $warehouseId);
        }

        $city = strtolower(trim((string) ($filters['city'] ?? '')));
        if ($city !== '' && $warehouseId === 0) {
            $query->whereHas('dispatchWarehouse', function (Builder $warehouseQuery) use ($city) {
                $warehouseQuery->whereRaw('LOWER(city) = ?', [$city]);
            });
        }

        $productId = (int) ($filters['product_id'] ?? 0);
        if ($productId > 0) {
            $query->where(function (Builder $rentalQuery) use ($productId) {
                $rentalQuery->where('product_id', $productId);
                if (Rental::hasRentalItemsTable()) {
                    $rentalQuery->orWhereHas('rentalItems', fn (Builder $itemQuery) => $itemQuery->where('product_id', $productId));
                }
            });
        } elseif (($filters['category'] ?? '') !== '') {
            $category = (string) $filters['category'];
            $query->where(function (Builder $rentalQuery) use ($category) {
                $rentalQuery->whereHas('product', fn (Builder $productQuery) => $productQuery->where('category', $category));
                if (Rental::hasRentalItemsTable()) {
                    $rentalQuery->orWhereHas('rentalItems.product', fn (Builder $productQuery) => $productQuery->where('category', $category));
                }
            });
        }

        return (int) $query->get()->sum(function (Rental $rental) {
            if (Rental::hasRentalItemsTable() && $rental->relationLoaded('rentalItems') && $rental->rentalItems->isNotEmpty()) {
                return (int) $rental->rentalItems->sum(function ($item) {
                    $ordered = max((int) ($item->ordered_quantity ?? $item->quantity ?? 0), 0);
                    $delivered = min(max((int) ($item->delivered_quantity ?? 0), 0), $ordered);
                    $returned = min(max((int) ($item->returned_quantity ?? 0), 0), $delivered);

                    return max($delivered - $returned, 0);
                });
            }

            return max((int) ($rental->quantity ?? 0), 0);
        });
    }

    private function rentalUnclassifiedAssets(
        int $organizationId,
        array $filters,
        Collection $rentalAssets,
        array $availableIds,
        int $activeRentedQuantity
    ): Collection {
        $availableIdLookup = array_fill_keys($availableIds, true);
        $assignmentAssetIds = \App\Models\RentalAsset::query()
            ->where('organization_id', $organizationId)
            ->whereNull('returned_at')
            ->whereHas('rental', function (Builder $query) use ($filters) {
                $query->where('status', 'active')->lifecycleStarted();

                $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
                if ($warehouseId > 0) {
                    $query->where('dispatch_warehouse_id', $warehouseId);
                } elseif (($filters['city'] ?? '') !== '') {
                    $city = strtolower(trim((string) $filters['city']));
                    $query->whereHas('dispatchWarehouse', function (Builder $warehouseQuery) use ($city) {
                        $warehouseQuery->whereRaw('LOWER(city) = ?', [$city]);
                    });
                }
            })
            ->pluck('asset_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $assignmentLookup = array_fill_keys($assignmentAssetIds->all(), true);

        $rentedAssets = $rentalAssets
            ->where('asset_status', Asset::STATUS_RENTED)
            ->sortBy([
                fn (Asset $asset) => empty($assignmentLookup[(int) $asset->id]) ? 1 : 0,
                fn (Asset $asset) => (int) $asset->id,
            ])
            ->values();

        $activeAssetIds = $rentedAssets
            ->take(min($activeRentedQuantity, $rentedAssets->count()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $activeLookup = array_fill_keys($activeAssetIds, true);
        $unclassified = collect();

        foreach ($rentalAssets as $asset) {
            $assetId = (int) $asset->id;
            $status = (string) ($asset->asset_status ?? '');
            $condition = (string) ($asset->condition_status ?? '');

            $reason = null;
            $reasonCode = null;
            $severity = 'Informational';
            $recommendedAction = 'Investigate Manually';

            if ($status === Asset::STATUS_RENTED && empty($activeLookup[$assetId])) {
                $reasonCode = 'stale_rented';
                $reason = 'Marked rented, but not part of the canonical active-rented quantity and has no active rental assignment.';
                $severity = 'Critical';
                $recommendedAction = 'Mark Available';
            } elseif ($status === Asset::STATUS_AVAILABLE && empty($availableIdLookup[$assetId])) {
                $reasonCode = 'available_not_ready';
                $reason = $condition !== ''
                    ? sprintf('Marked available, but excluded from rental-ready availability because condition is "%s".', $condition)
                    : 'Marked available, but excluded from rental-ready availability by canonical availability rules.';
                $severity = in_array($condition, ['repair', 'damaged'], true) ? 'Warning' : 'Informational';
                $recommendedAction = in_array($condition, ['repair', 'damaged'], true) ? 'Move to Maintenance' : 'Investigate Manually';
            } elseif (! in_array($status, Asset::RENTAL_ASSET_STATUSES, true)) {
                $reasonCode = 'unmapped_status';
                $reason = 'Rental asset has an unmapped or non-rental status and cannot be classified into the standard reconciliation buckets.';
                $severity = 'Critical';
                $recommendedAction = 'Investigate Manually';
            }

            if ($reason === null) {
                continue;
            }

            $lastMovement = $asset->stockMovements->sortByDesc('movement_at')->first();
            $latestAssignment = $asset->rentalAssignments()
                ->with(['rental:id,status,customer_name'])
                ->latest('assigned_at')
                ->first();

            $updatedAt = $asset->updated_at instanceof Carbon ? $asset->updated_at : ($asset->updated_at ? Carbon::parse($asset->updated_at) : null);
            $lastMovementAt = $lastMovement?->movement_at instanceof Carbon
                ? $lastMovement->movement_at
                : ($lastMovement?->movement_at ? Carbon::parse($lastMovement->movement_at) : null);
            $latestAssignmentRental = $latestAssignment?->rental;
            if ($recommendedAction === 'Investigate Manually' && $latestAssignmentRental && in_array((string) $latestAssignmentRental->delivery_status, ['completed', 'pending'], true)) {
                $recommendedAction = 'Review Delivery/Pickup';
                $severity = 'Warning';
            }

            $unclassified->push([
                'asset_id' => $assetId,
                'serial_number' => $asset->serial_number,
                'barcode_value' => $asset->barcode_value,
                'product' => $asset->product?->name ?: 'Unknown Product',
                'asset_status' => $status,
                'condition_status' => $condition ?: null,
                'warehouse' => $asset->warehouse?->name ?: null,
                'linked_rental_id' => $latestAssignment?->rental_id,
                'linked_rental_reference' => $latestAssignment?->rental
                    ? ('Rental #' . $latestAssignment->rental->id)
                    : ($latestAssignment?->rental_id ? ('Rental #' . $latestAssignment->rental_id) : null),
                'linked_rental_status' => $latestAssignment?->rental?->status,
                'linked_delivery_status' => $latestAssignment?->rental?->delivery_status,
                'linked_pickup_status' => $latestAssignment?->rental?->pickup_status,
                'last_stock_movement' => $lastMovement?->movement_type,
                'last_stock_movement_at' => optional($lastMovementAt)->format('Y-m-d H:i:s'),
                'updated_at' => optional($updatedAt)->format('Y-m-d H:i:s'),
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'severity' => $severity,
                'recommended_action' => $recommendedAction,
                'unclassified_for_days' => $updatedAt ? (int) floor($updatedAt->diffInDays(now())) : null,
                'days_since_last_valid_movement' => $lastMovementAt ? (int) floor($lastMovementAt->diffInDays(now())) : null,
                'days_since_last_update' => $updatedAt ? (int) floor($updatedAt->diffInDays(now())) : null,
            ]);
        }

        return $unclassified
            ->sortBy([
                fn (array $asset) => $asset['asset_status'] === Asset::STATUS_RENTED ? 0 : 1,
                fn (array $asset) => $asset['asset_id'],
            ])
            ->values();
    }
}
