<?php

namespace App\Services\Inventory;

use App\Models\Asset;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class InventoryIntelligenceService
{
    private function applyCategoryConstraint(Builder $query, string $category): Builder
    {
        return ctype_digit($category) && Schema::hasColumn('products', 'category_id')
            ? $query->where('category_id', (int) $category)
            : $query->where('category', $category);
    }

    public function build(int $organizationId, array $filters = []): array
    {
        [$timeScope, $month, $year, $startDate, $endDate, $bucketMode, $periodLabel] = $this->resolveTimeWindow($filters, $organizationId);
        $hasLocationFilter = (int) ($filters['warehouse_id'] ?? 0) > 0
            || trim((string) ($filters['city'] ?? '')) !== '';
        $useCurrentAvailabilityAnchorWindow = !$hasLocationFilter
            && $timeScope === 'monthly'
            && now()->year === $year
            && now()->month === $month;
        $dates = $this->buildBuckets($startDate, $endDate, $bucketMode);

        $productQuery = Product::query()
            ->where('organization_id', $organizationId)
            ->withCount([
                'saleUnits as serialized_sale_units_available_count' => fn ($query) => $query->whereIn('asset_status', [
                    Asset::STATUS_AVAILABLE_FOR_SALE,
                    Asset::STATUS_AVAILABLE,
                ]),
            ]);

        if (($filters['product_id'] ?? null) !== null) {
            $productQuery->whereKey((int) $filters['product_id']);
        }

        if (($filters['category'] ?? '') !== '') {
            $this->applyCategoryConstraint($productQuery, (string) $filters['category']);
        }

        $mode = strtolower((string) ($filters['mode'] ?? 'all'));
        if ($mode === 'rent') {
            $productQuery->where(function (Builder $query) {
                $query->whereIn('product_type', [Product::TYPE_RENTABLE, Product::TYPE_BOTH])
                    ->orWhereIn('stock_mode', [
                        Product::STOCK_MODE_TRACKED_RENTAL,
                        Product::STOCK_MODE_TRACKED_BOTH,
                    ]);
            });
        } elseif ($mode === 'sale') {
            $productQuery->where(function (Builder $query) {
                $query->whereIn('product_type', [Product::TYPE_SELLABLE, Product::TYPE_BOTH])
                    ->orWhereIn('stock_mode', [
                        Product::STOCK_MODE_TRACKED_SALE,
                        Product::STOCK_MODE_TRACKED_BOTH,
                    ]);
            });
        }

        $products = $productQuery
            ->orderBy('name')
            ->get(['id', 'name', 'brand', 'model_name', 'category', 'product_type', 'stock_mode', 'total_quantity', 'available_quantity']);

        if ($products->isEmpty()) {
            return [
                'time_scope' => $timeScope,
                'bucket_mode' => $bucketMode,
                'period_label' => $periodLabel,
                'month' => $month,
                'year' => $year,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dates' => $dates,
                'rows' => collect(),
                'summary' => $this->emptySummary(),
                'filters' => $this->filterOptions($organizationId),
                'drilldown' => null,
            ];
        }

        $productIds = $products->pluck('id')->all();
        $movements = $this->baseMovementQuery($organizationId, $productIds, $endDate, $filters)
            ->get();

        $movements = $movements
            ->filter(fn (StockMovement $movement) => $this->movementTouchesLocation($movement, $filters))
            ->values();

        $syntheticMovements = $this->syntheticOperationalMovements($organizationId, $products, $movements, $endDate, $filters);

        $movements = $movements
            ->concat($syntheticMovements)
            ->sortBy([
                fn (StockMovement $movement) => optional($movement->movement_at)->timestamp ?? 0,
                fn (StockMovement $movement) => (int) ($movement->id ?? 0),
                fn (StockMovement $movement) => (int) ($movement->product_id ?? 0),
            ])
            ->values();

        $totalsByProduct = [];

        foreach ($products as $product) {
            $productMovements = $movements
                ->filter(fn (StockMovement $movement) => (int) $movement->product_id === (int) $product->id)
                ->values();

            $totalsByProduct[$product->id] = [
                'rental_out_total' => $productMovements
                    ->filter(fn (StockMovement $movement) => in_array($movement->movement_type, [
                        StockMovement::TYPE_RENTAL_OUT,
                        StockMovement::TYPE_DELIVERY,
                    ], true))
                    ->sum('quantity'),
                'rental_return_total' => $productMovements
                    ->filter(fn (StockMovement $movement) => in_array($movement->movement_type, [
                        StockMovement::TYPE_PICKUP_RETURN,
                        StockMovement::TYPE_RETURN_VERIFICATION,
                    ], true))
                    ->sum('quantity'),
                'sale_out_total' => $productMovements
                    ->filter(fn (StockMovement $movement) => $movement->movement_type === StockMovement::TYPE_SALE)
                    ->sum('quantity'),
            ];
        }

        $openingByProduct = [];
        foreach ($products as $product) {
            $openingByProduct[$product->id] = $movements
                ->filter(fn (StockMovement $movement) => (int) $movement->product_id === (int) $product->id)
                ->filter(fn (StockMovement $movement) => $movement->movement_at->lt($startDate))
                ->sum(fn (StockMovement $movement) => $this->availableDelta($movement, $filters));
        }

        $currentRentalUsageByProduct = $this->currentRentalUsageByProduct($organizationId, $products, $filters);

        $rows = collect();
        foreach ($products as $product) {
            $daily = [];
            $productMovements = $movements
                ->filter(fn (StockMovement $movement) => (int) $movement->product_id === (int) $product->id)
                ->values();
            $runningClosing = (int) ($openingByProduct[$product->id] ?? 0);
            $movementVolume = 0;

            foreach ($dates as $date) {
                $bucketKey = (string) $date['key'];
                $dayMovements = $productMovements
                    ->filter(fn (StockMovement $movement) => $movement->movement_at->betweenIncluded($date['start'], $date['end']))
                    ->values();
                $metrics = $this->dayMetrics($dayMovements, $filters);
                $opening = $runningClosing;
                $closing = $opening + $metrics['net_change'];
                $movementVolume += $metrics['movement_volume'];

                $metrics['opening_stock'] = $opening;
                $metrics['closing_stock'] = $closing;
                $metrics['utilization_pct'] = $opening > 0
                    ? round(($metrics['rental_out'] / max($opening, 1)) * 100, 1)
                    : 0.0;

                $daily[$bucketKey] = $metrics;
                $runningClosing = $closing;
            }

            $hasRealOperationalLedgerInWindow = $productMovements
                ->contains(fn (StockMovement $movement) => $movement->exists
                    && $movement->movement_type !== StockMovement::TYPE_OPENING
                    && $movement->movement_at->betweenIncluded($startDate, $endDate));
            $useCurrentAvailabilityAnchor = $useCurrentAvailabilityAnchorWindow && !$hasRealOperationalLedgerInWindow;

            if ($useCurrentAvailabilityAnchor) {
                $currentClosing = max((int) ($product->available_quantity ?? 0), 0);
                $operationalNetMonth = (int) collect($daily)->sum('operational_net_change');
                $reconstructedOpening = $currentClosing - $operationalNetMonth;
                $runningClosing = $reconstructedOpening;

                foreach ($dates as $date) {
                    $key = (string) $date['key'];
                    $metrics = $daily[$key];
                    $metrics['opening_stock'] = $runningClosing;
                    $metrics['net_change'] = (int) ($metrics['operational_net_change'] ?? 0);
                    $metrics['closing_stock'] = $runningClosing + $metrics['net_change'];
                    $metrics['utilization_pct'] = $runningClosing > 0
                        ? round(($metrics['rental_out'] / max($runningClosing, 1)) * 100, 1)
                        : 0.0;
                    $daily[$key] = $metrics;
                    $runningClosing = $metrics['closing_stock'];
                }
            }

            $hasActivity = collect($daily)->contains(function (array $metrics) {
                return $metrics['opening_stock'] !== 0
                    || $metrics['closing_stock'] !== 0
                    || $metrics['movement_volume'] !== 0;
            });

            if (!$hasActivity && ($filters['product_id'] ?? null) === null) {
                continue;
            }

            $rows->push([
                'product' => $product,
                'daily' => $daily,
                'movement_volume' => $movementVolume,
                'closing_stock' => $useCurrentAvailabilityAnchor
                    ? max((int) ($product->available_quantity ?? 0), 0)
                    : $runningClosing,
                'opening_stock' => $useCurrentAvailabilityAnchor
                    ? ((int) max((int) ($product->available_quantity ?? 0), 0) - (int) collect($daily)->sum('operational_net_change'))
                    : (int) ($openingByProduct[$product->id] ?? 0),
                'totals' => $totalsByProduct[$product->id] ?? [
                    'rental_out_total' => 0,
                    'rental_return_total' => 0,
                    'sale_out_total' => 0,
                ],
                'current_rental_in_use' => (int) ($currentRentalUsageByProduct[$product->id] ?? 0),
                'monthly_summary' => [
                    'opening_stock' => $useCurrentAvailabilityAnchor
                        ? ((int) max((int) ($product->available_quantity ?? 0), 0) - (int) collect($daily)->sum('operational_net_change'))
                        : max($runningClosing - (int) collect($daily)->sum('operational_net_change'), 0),
                    'closing_stock' => $useCurrentAvailabilityAnchor
                        ? max((int) ($product->available_quantity ?? 0), 0)
                        : $runningClosing,
                    'rental_out' => collect($daily)->sum('rental_out'),
                    'rental_return' => collect($daily)->sum('rental_return'),
                    'sale_out' => collect($daily)->sum('sale_out'),
                    'net_change' => collect($daily)->sum('operational_net_change'),
                    'utilization_pct' => $this->rowUtilizationPct(
                        $product,
                        $useCurrentAvailabilityAnchor ? max((int) ($product->available_quantity ?? 0), 0) : $runningClosing,
                        (int) ($currentRentalUsageByProduct[$product->id] ?? 0),
                        $filters
                    ),
                ],
            ]);
        }

        $rows = $rows
            ->sortBy(fn (array $row) => strtolower((string) $row['product']->name))
            ->values();

        $assetReconciliation = $this->assetReconciliation($organizationId, $filters);

        $summary = $this->summary(
            $organizationId,
            $products,
            $rows,
            $movements,
            $dates,
            $filters,
            $startDate,
            $endDate,
            $assetReconciliation
        );

            return [
            'time_scope' => $timeScope,
            'bucket_mode' => $bucketMode,
            'period_label' => $periodLabel,
            'month' => $month,
            'year' => $year,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dates' => $dates,
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $this->filterOptions($organizationId),
            'drilldown' => $this->drilldownFromMovements($organizationId, $filters, $movements),
            'reconciliation' => $this->reconciliation($rows, $movements, $syntheticMovements, $filters),
            'asset_reconciliation' => $assetReconciliation,
        ];
    }

    public function movementBreakdown(int $organizationId, array $filters = []): Collection
    {
        $report = $this->build($organizationId, $filters);
        $drill = $report['drilldown'] ?? null;

        return $drill['movements'] ?? collect();
    }

    private function resolveTimeWindow(array $filters, int $organizationId): array
    {
        $timeScope = strtolower((string) ($filters['time_scope'] ?? 'monthly'));
        $month = max(1, min(12, (int) ($filters['month'] ?? now()->month)));
        $year = max(2000, min(2100, (int) ($filters['year'] ?? now()->year)));
        if ($timeScope === 'date_range') {
            $from = $filters['from_date'] ?? null;
            $to = $filters['to_date'] ?? null;
            $startDate = $from ? Carbon::parse((string) $from)->startOfDay() : Carbon::create($year, $month, 1)->startOfDay();
            $endDate = $to ? Carbon::parse((string) $to)->endOfDay() : $startDate->copy()->endOfMonth()->endOfDay();
            if ($endDate->lt($startDate)) {
                [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
            }
            $days = $startDate->diffInDays($endDate) + 1;
            $bucketMode = $days <= 31 ? 'daily' : ($days <= 90 ? 'weekly' : 'monthly');
            $periodLabel = sprintf('%s to %s', $startDate->format('d M Y'), $endDate->format('d M Y'));

            return [$timeScope, $month, $year, $startDate, $endDate, $bucketMode, $periodLabel];
        }

        if ($timeScope === 'all_time') {
            $startDate = $this->earliestActivityDate($organizationId)?->startOfMonth() ?? now()->startOfMonth();
            $endDate = now()->endOfMonth();
            $bucketMode = 'monthly';
            $periodLabel = 'All Time';

            return [$timeScope, $month, $year, $startDate, $endDate, $bucketMode, $periodLabel];
        }

        $timeScope = 'monthly';
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth()->endOfDay();
        $bucketMode = 'daily';
        $periodLabel = $startDate->format('F Y');

        return [$timeScope, $month, $year, $startDate, $endDate, $bucketMode, $periodLabel];
    }

    private function buildBuckets(Carbon $startDate, Carbon $endDate, string $bucketMode): Collection
    {
        if ($bucketMode === 'weekly') {
            $buckets = collect();
            $cursor = $startDate->copy()->startOfDay();
            while ($cursor->lte($endDate)) {
                $bucketStart = $cursor->copy()->startOfDay();
                $bucketEnd = $cursor->copy()->addDays(6)->endOfDay();
                if ($bucketEnd->gt($endDate)) {
                    $bucketEnd = $endDate->copy()->endOfDay();
                }
                $buckets->push([
                    'key' => $bucketStart->toDateString(),
                    'label' => $bucketStart->format('d M').'-'.$bucketEnd->format('d M'),
                    'start' => $bucketStart,
                    'end' => $bucketEnd,
                ]);
                $cursor = $bucketEnd->copy()->addDay()->startOfDay();
            }

            return $buckets;
        }

        if ($bucketMode === 'monthly') {
            return collect(CarbonPeriod::create($startDate->copy()->startOfMonth(), '1 month', $endDate->copy()->startOfMonth()))
                ->map(function (Carbon $date) use ($endDate) {
                    $bucketStart = $date->copy()->startOfMonth();
                    $bucketEnd = $date->copy()->endOfMonth()->endOfDay();
                    if ($bucketEnd->gt($endDate)) {
                        $bucketEnd = $endDate->copy()->endOfDay();
                    }

                    return [
                        'key' => $bucketStart->format('Y-m'),
                        'label' => $bucketStart->format('M Y'),
                        'start' => $bucketStart,
                        'end' => $bucketEnd,
                    ];
                })
                ->values();
        }

        return collect(CarbonPeriod::create($startDate, $endDate))
            ->map(function (Carbon $date) {
                return [
                    'key' => $date->toDateString(),
                    'label' => $date->format('d'),
                    'start' => $date->copy()->startOfDay(),
                    'end' => $date->copy()->endOfDay(),
                ];
            })
            ->values();
    }

    private function earliestActivityDate(int $organizationId): ?Carbon
    {
        $dates = collect([
            StockMovement::query()->where('organization_id', $organizationId)->min('movement_at'),
            Sale::query()->where('organization_id', $organizationId)->min('sale_date'),
            Rental::query()->where('organization_id', $organizationId)->min('start_date'),
            Delivery::query()->where('organization_id', $organizationId)->min('scheduled_at'),
        ])->filter();

        if ($dates->isEmpty()) {
            return null;
        }

        return Carbon::parse($dates->sort()->first());
    }

    private function baseMovementQuery(int $organizationId, array $productIds, Carbon $endDate, array $filters): Builder
    {
        return StockMovement::query()
            ->with([
                'product:id,name,brand,model_name,category',
                'asset:id,product_id,serial_number,barcode_value,batch_number',
                'fromWarehouse:id,name,city',
                'toWarehouse:id,name,city',
                'performedBy:id,name',
                'rental:id,customer_id,customer_name',
                'rental.customer:id,name,phone,city',
                'sale:id,customer_id',
                'sale.customer:id,name,phone,city',
                'delivery:id,type,status',
            ])
            ->where('organization_id', $organizationId)
            ->whereIn('product_id', $productIds)
            ->whereDate('movement_at', '<=', $endDate->toDateString())
            ->orderBy('movement_at')
            ->orderBy('id');
    }

    private function movementTouchesLocation(StockMovement $movement, array $filters): bool
    {
        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            return (int) $movement->from_warehouse_id === $warehouseId
                || (int) $movement->to_warehouse_id === $warehouseId;
        }

        $city = strtolower(trim((string) ($filters['city'] ?? '')));
        if ($city !== '') {
            $fromCity = strtolower(trim((string) ($movement->fromWarehouse?->city ?? '')));
            $toCity = strtolower(trim((string) ($movement->toWarehouse?->city ?? '')));

            return $fromCity === $city || $toCity === $city;
        }

        return true;
    }

    private function availableDelta(StockMovement $movement, array $filters): int
    {
        $baseDelta = match ($movement->movement_type) {
            StockMovement::TYPE_OPENING,
            StockMovement::TYPE_PURCHASE,
            StockMovement::TYPE_IMPORT,
            StockMovement::TYPE_ADD_STOCK,
            StockMovement::TYPE_PICKUP_RETURN,
            StockMovement::TYPE_RETURN_VERIFICATION,
            StockMovement::TYPE_CORRECTION_ADD => (int) $movement->quantity,
            StockMovement::TYPE_SALE,
            StockMovement::TYPE_DELIVERY,
            StockMovement::TYPE_RENTAL_OUT,
            StockMovement::TYPE_REPAIR,
            StockMovement::TYPE_SCRAP,
            StockMovement::TYPE_CORRECTION_REMOVE => -1 * (int) $movement->quantity,
            StockMovement::TYPE_WAREHOUSE_TRANSFER,
            StockMovement::TYPE_CORRECTION_TRANSFER => 0,
            StockMovement::TYPE_MANUAL_ADJUSTMENT => $this->manualAdjustmentDelta($movement),
            default => 0,
        };

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            if (in_array($movement->movement_type, [
                StockMovement::TYPE_WAREHOUSE_TRANSFER,
                StockMovement::TYPE_CORRECTION_TRANSFER,
            ], true)) {
                $delta = 0;
                if ((int) $movement->from_warehouse_id === $warehouseId) {
                    $delta -= (int) $movement->quantity;
                }
                if ((int) $movement->to_warehouse_id === $warehouseId) {
                    $delta += (int) $movement->quantity;
                }

                return $delta;
            }

            if ((int) $movement->from_warehouse_id !== $warehouseId && (int) $movement->to_warehouse_id !== $warehouseId) {
                return 0;
            }

            return $baseDelta;
        }

        $city = strtolower(trim((string) ($filters['city'] ?? '')));
        if ($city !== '') {
            $fromMatches = strtolower(trim((string) ($movement->fromWarehouse?->city ?? ''))) === $city;
            $toMatches = strtolower(trim((string) ($movement->toWarehouse?->city ?? ''))) === $city;

            if (in_array($movement->movement_type, [
                StockMovement::TYPE_WAREHOUSE_TRANSFER,
                StockMovement::TYPE_CORRECTION_TRANSFER,
            ], true)) {
                $delta = 0;
                if ($fromMatches) {
                    $delta -= (int) $movement->quantity;
                }
                if ($toMatches) {
                    $delta += (int) $movement->quantity;
                }

                return $delta;
            }

            if (!$fromMatches && !$toMatches) {
                return 0;
            }
        }

        return $baseDelta;
    }

    private function manualAdjustmentDelta(StockMovement $movement): int
    {
        $fromStatus = strtolower((string) ($movement->from_status ?? ''));
        $toStatus = strtolower((string) ($movement->to_status ?? ''));

        if ($toStatus === 'available' && $fromStatus !== 'available') {
            return (int) $movement->quantity;
        }

        if ($fromStatus === 'available' && $toStatus !== 'available') {
            return -1 * (int) $movement->quantity;
        }

        return 0;
    }

    private function dayMetrics(Collection $dayMovements, array $filters): array
    {
        $metrics = [
            'opening_stock' => 0,
            'closing_stock' => 0,
            'rental_out' => 0,
            'rental_return' => 0,
            'sale_out' => 0,
            'transfers_in' => 0,
            'transfers_out' => 0,
            'repair' => 0,
            'scrap' => 0,
            'net_change' => 0,
            'utilization_pct' => 0.0,
            'movement_volume' => 0,
            'opening_delta' => 0,
            'operational_net_change' => 0,
        ];

        foreach ($dayMovements as $movement) {
            $delta = $this->availableDelta($movement, $filters);
            $metrics['net_change'] += $delta;

            if ($movement->movement_type === StockMovement::TYPE_OPENING) {
                $metrics['opening_delta'] += $delta;
            } else {
                $metrics['movement_volume'] += abs((int) $movement->quantity);
                $metrics['operational_net_change'] += $delta;
            }

            switch ($movement->movement_type) {
                case StockMovement::TYPE_RENTAL_OUT:
                case StockMovement::TYPE_DELIVERY:
                    $metrics['rental_out'] += (int) $movement->quantity;
                    break;
                case StockMovement::TYPE_PICKUP_RETURN:
                case StockMovement::TYPE_RETURN_VERIFICATION:
                    $metrics['rental_return'] += (int) $movement->quantity;
                    break;
                case StockMovement::TYPE_SALE:
                    $metrics['sale_out'] += (int) $movement->quantity;
                    break;
                case StockMovement::TYPE_REPAIR:
                    $metrics['repair'] += (int) $movement->quantity;
                    break;
                case StockMovement::TYPE_SCRAP:
                    $metrics['scrap'] += (int) $movement->quantity;
                    break;
                case StockMovement::TYPE_WAREHOUSE_TRANSFER:
                case StockMovement::TYPE_CORRECTION_TRANSFER:
                    $metrics['transfers_in'] += $this->transferIn($movement, $filters);
                    $metrics['transfers_out'] += $this->transferOut($movement, $filters);
                    break;
            }
        }

        return $metrics;
    }

    private function transferIn(StockMovement $movement, array $filters): int
    {
        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            return (int) $movement->to_warehouse_id === $warehouseId ? (int) $movement->quantity : 0;
        }

        $city = strtolower(trim((string) ($filters['city'] ?? '')));
        if ($city !== '') {
            return strtolower(trim((string) ($movement->toWarehouse?->city ?? ''))) === $city
                ? (int) $movement->quantity
                : 0;
        }

        return (int) $movement->quantity;
    }

    private function transferOut(StockMovement $movement, array $filters): int
    {
        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            return (int) $movement->from_warehouse_id === $warehouseId ? (int) $movement->quantity : 0;
        }

        $city = strtolower(trim((string) ($filters['city'] ?? '')));
        if ($city !== '') {
            return strtolower(trim((string) ($movement->fromWarehouse?->city ?? ''))) === $city
                ? (int) $movement->quantity
                : 0;
        }

        return (int) $movement->quantity;
    }

    private function summary(
        int $organizationId,
        Collection $products,
        Collection $rows,
        Collection $movements,
        Collection $dates,
        array $filters = [],
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?array $assetReconciliation = null
    ): array
    {
        $lastDate = $dates->last()['key'] ?? null;
        $totalOpening = 0;
        $totalAvailable = 0;
        $totalRentalInUse = 0;
        $totalRentalBasis = 0;
        $rowRentalOut = 0;
        $rowReturns = 0;
        $rowSaleOut = 0;
        $startDate ??= $dates->first()['start'] ?? null;
        $endDate ??= $dates->last()['end'] ?? null;
        $hasLocationFilter = (int) ($filters['warehouse_id'] ?? 0) > 0
            || trim((string) ($filters['city'] ?? '')) !== '';
        $highMovement = $rows
            ->sortByDesc('movement_volume')
            ->take(5)
            ->map(fn (array $row) => $row['product']->name)
            ->values();

        foreach ($rows as $row) {
            $monthlySummary = $row['monthly_summary'] ?? [];
            $totalOpening += (int) ($monthlySummary['opening_stock'] ?? 0);
            if ($lastDate !== null) {
                $totalAvailable += (int) ($row['daily'][$lastDate]['closing_stock'] ?? 0);
            }

            $product = $row['product'];
            $totals = $row['totals'] ?? [];
            $saleOutTotal = (int) ($totals['sale_out_total'] ?? 0);
            $rentalOutTotal = (int) ($totals['rental_out_total'] ?? 0);
            $rentalReturnTotal = (int) ($totals['rental_return_total'] ?? 0);
            $currentRentalInUse = max((int) ($row['current_rental_in_use'] ?? 0), 0);
            $rowRentalOut += (int) ($monthlySummary['rental_out'] ?? 0);
            $rowReturns += (int) ($monthlySummary['rental_return'] ?? 0);
            $rowSaleOut += (int) ($monthlySummary['sale_out'] ?? 0);

            $isRentalProduct = (method_exists($product, 'tracksRentalStock') && $product->tracksRentalStock())
                || (method_exists($product, 'isRentableProduct') && $product->isRentableProduct());
            if ($isRentalProduct) {
                $inUse = max($rentalOutTotal - $rentalReturnTotal, 0);
                if ($currentRentalInUse > $inUse) {
                    $inUse = $currentRentalInUse;
                }
                $effectiveAvailable = $hasLocationFilter
                    ? max((int) ($row['closing_stock'] ?? 0), 0)
                    : max((int) ($product->available_quantity ?? $row['closing_stock'] ?? 0), 0);
                $basis = $effectiveAvailable + $inUse;
                $totalRentalInUse += $inUse;
                $totalRentalBasis += $basis;
            }
        }

        $operational = $this->authoritativeOperationalSummary(
            $organizationId,
            $products,
            $movements,
            $filters,
            $startDate,
            $endDate
        );

        $totalRentalOut = (int) ($operational['rental_out'] ?? 0);
        $totalReturns = (int) ($operational['returns'] ?? 0);
        $totalSaleOut = (int) ($operational['sale_out'] ?? 0);
        $totalSaleOrders = (int) ($operational['sale_orders'] ?? 0);
        if ($totalRentalOut === 0) {
            $totalRentalOut = $rowRentalOut;
        }
        if ($totalReturns === 0) {
            $totalReturns = $rowReturns;
        }
        if ($totalSaleOut === 0) {
            $totalSaleOut = $rowSaleOut;
        }
        $adjustmentNet = (int) ($operational['adjustment_net'] ?? 0);
        $totalNetChange = ($totalReturns + $adjustmentNet) - ($totalRentalOut + $totalSaleOut);

        $currentStateBasis = $this->currentStateSummaryBasis(
            $organizationId,
            $products,
            $filters,
            $startDate,
            $endDate,
            $assetReconciliation
        );

        if ($currentStateBasis !== null) {
            $totalOpening = (int) $currentStateBasis['opening_stock'];
            $totalAvailable = (int) $currentStateBasis['current_available_stock'];
            $totalNetChange = $totalAvailable - $totalOpening;
        } else {
            if (!$hasLocationFilter) {
                $totalAvailable = (int) $rows->sum(fn (array $row) => max((int) ($row['closing_stock'] ?? 0), 0));
            }

            if (!$hasLocationFilter) {
                $totalOpening = $totalAvailable - $totalNetChange;
            }

            $closingAvailable = $totalOpening + $totalNetChange;
            if ($closingAvailable !== $totalAvailable) {
                $totalAvailable = $closingAvailable;
            }
        }

        $lowStockProducts = $hasLocationFilter
            ? $rows->filter(fn (array $row) => (int) $row['closing_stock'] <= 2)->count()
            : $rows->filter(fn (array $row) => max((int) ($row['product']->available_quantity ?? 0), 0) <= 2)->count();

        return [
            'opening_stock' => $totalOpening,
            'total_available_stock' => $totalAvailable,
            'closing_stock' => $totalAvailable,
            'total_rental_out' => $totalRentalOut,
            'total_returns' => $totalReturns,
            'sale_orders' => $totalSaleOrders,
            'total_sale_out' => $totalSaleOut,
            'net_change' => $totalNetChange,
            'rental_utilization_pct' => $totalRentalBasis > 0 ? round(($totalRentalInUse / max($totalRentalBasis, 1)) * 100, 1) : 0.0,
            'sale_stock_consumed' => $totalSaleOut,
            'low_stock_products' => $lowStockProducts,
            'high_movement_products' => $highMovement,
            'stock_basis_reconciliation' => $currentStateBasis,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'opening_stock' => 0,
            'total_available_stock' => 0,
            'closing_stock' => 0,
            'total_rental_out' => 0,
            'total_returns' => 0,
            'sale_orders' => 0,
            'total_sale_out' => 0,
            'net_change' => 0,
            'rental_utilization_pct' => 0.0,
            'sale_stock_consumed' => 0,
            'low_stock_products' => 0,
            'high_movement_products' => collect(),
            'stock_basis_reconciliation' => null,
        ];
    }

    private function currentStateSummaryBasis(
        int $organizationId,
        Collection $products,
        array $filters,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?array $assetReconciliation
    ): ?array {
        $hasLocationFilter = (int) ($filters['warehouse_id'] ?? 0) > 0
            || trim((string) ($filters['city'] ?? '')) !== '';
        $isGlobalAllView = strtolower((string) ($filters['mode'] ?? 'all')) === 'all'
            && (($filters['product_id'] ?? null) === null || (int) ($filters['product_id'] ?? 0) === 0)
            && trim((string) ($filters['category'] ?? '')) === '';
        $isCurrentMonth = $startDate !== null
            && $endDate !== null
            && now()->year === $startDate->year
            && now()->month === $startDate->month;

        if ($hasLocationFilter || !$isCurrentMonth || !$isGlobalAllView) {
            return null;
        }

        $saleUnitsAvailableNow = (int) $products->sum(function (Product $product): int {
            if ($product->usesUntrackedStock()) {
                return $product->isSellableProduct()
                    ? max((int) ($product->available_quantity ?? 0), 0)
                    : 0;
            }

            return (int) ($product->serialized_sale_units_available_count ?? 0);
        });

        $rentalReconciliation = (array) ($assetReconciliation['rental_reconciliation'] ?? []);
        $rentalAssetsTotal = (int) ($rentalReconciliation['total_rental_assets'] ?? 0);
        $rentalAssetsAvailableNow = (int) ($rentalReconciliation['available'] ?? 0);
        $operationalSummary = $this->authoritativeOperationalSummary(
            $organizationId,
            $products,
            collect(),
            $filters,
            $startDate,
            $endDate
        );
        $saleUnitsSoldInPeriod = (int) ($operationalSummary['sale_out'] ?? 0);

        $openingStock = $saleUnitsAvailableNow + $saleUnitsSoldInPeriod + $rentalAssetsTotal;
        $currentAvailableStock = $saleUnitsAvailableNow + $rentalAssetsAvailableNow;
        $rentalUnavailableNow = max($rentalAssetsTotal - $rentalAssetsAvailableNow, 0);

        $rentalUnavailableBreakdown = [
            'rented' => (int) ($rentalReconciliation['active_rented'] ?? 0),
            'maintenance' => (int) ($rentalReconciliation['maintenance'] ?? 0),
            'awaiting_verification' => (int) ($rentalReconciliation['awaiting_verification'] ?? 0),
            'reserved' => (int) ($rentalReconciliation['reserved'] ?? 0),
            'transfer_in_progress' => (int) ($rentalReconciliation['transfer_in_progress'] ?? 0),
            'sold' => 0,
            'retired' => (int) ($rentalReconciliation['retired'] ?? 0),
        ];
        $rentalUnavailableExplained = array_sum($rentalUnavailableBreakdown);

        return [
            'sale_units_available_now' => $saleUnitsAvailableNow,
            'sale_units_sold_in_period' => $saleUnitsSoldInPeriod,
            'sale_orders' => (int) ($operationalSummary['sale_orders'] ?? 0),
            'rental_assets_total' => $rentalAssetsTotal,
            'rental_assets_available_now' => $rentalAssetsAvailableNow,
            'current_available_stock' => $currentAvailableStock,
            'opening_stock' => $openingStock,
            'rental_unavailable_now' => $rentalUnavailableNow,
            'rental_unavailable_breakdown' => $rentalUnavailableBreakdown,
            'rental_unavailable_explained' => $rentalUnavailableExplained,
            'rental_unavailable_unexplained' => max($rentalUnavailableNow - $rentalUnavailableExplained, 0),
        ];
    }

    private function drilldownFromMovements(int $organizationId, array $filters, Collection $movements): ?array
    {
        $productId = (int) ($filters['drill_product_id'] ?? 0);
        $bucketStartValue = trim((string) ($filters['drill_bucket_start'] ?? ''));
        $bucketEndValue = trim((string) ($filters['drill_bucket_end'] ?? ''));
        $date = trim((string) ($filters['drill_date'] ?? ''));

        if ($productId <= 0 || ($date === '' && $bucketStartValue === '')) {
            return null;
        }

        $bucketStart = $bucketStartValue !== ''
            ? Carbon::parse($bucketStartValue)->startOfDay()
            : Carbon::parse($date)->startOfDay();
        $bucketEnd = $bucketEndValue !== ''
            ? Carbon::parse($bucketEndValue)->endOfDay()
            : Carbon::parse($date)->endOfDay();
        $bucketLabel = trim((string) ($filters['drill_bucket_label'] ?? ''));

        $movements = $movements
            ->filter(fn (StockMovement $movement) => (int) $movement->organization_id === $organizationId)
            ->filter(fn (StockMovement $movement) => (int) $movement->product_id === $productId)
            ->filter(fn (StockMovement $movement) => $movement->movement_at->betweenIncluded($bucketStart, $bucketEnd))
            ->values();

        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->find($productId);

        if (!$product) {
            return null;
        }

        return [
            'product' => $product,
            'date' => $bucketStart,
            'label' => $bucketLabel !== '' ? $bucketLabel : $bucketStart->format('d M Y'),
            'start' => $bucketStart,
            'end' => $bucketEnd,
            'movements' => $movements,
        ];
    }

    private function reconciliation(Collection $rows, Collection $movements, Collection $syntheticMovements, array $filters): array
    {
        $hasLocationFilter = (int) ($filters['warehouse_id'] ?? 0) > 0
            || trim((string) ($filters['city'] ?? '')) !== '';

        $ledgerMovements = $movements->reject(fn (StockMovement $movement) => !$movement->exists)->values();
        $synthetic = $syntheticMovements->values();

        $warnings = collect();
        if (!$hasLocationFilter) {
            foreach ($rows as $row) {
                $product = $row['product'];
                $closing = (int) ($row['closing_stock'] ?? 0);
                $currentAvailable = max((int) ($product->available_quantity ?? $closing), 0);

                if ($closing !== $currentAvailable) {
                    $warnings->push([
                        'type' => 'quantity_mismatch',
                        'product' => $product->name,
                        'message' => sprintf(
                            '%s month-end closing is %d, but current available stock is %d.',
                            $product->name,
                            $closing,
                            $currentAvailable
                        ),
                    ]);
                }
            }
        }

        $syntheticByType = $synthetic->groupBy('movement_type');
        $ledgerByType = $ledgerMovements->groupBy('movement_type');

        return [
            'ledger_movement_count' => $ledgerMovements->count(),
            'fallback_movement_count' => $synthetic->count(),
            'missing_ledger_records_detected' => $synthetic->count(),
            'ledger_quantities' => [
                'sale_out' => (int) ($ledgerByType->get(StockMovement::TYPE_SALE, collect())->sum('quantity')),
                'rental_out' => (int) ($ledgerByType->get(StockMovement::TYPE_DELIVERY, collect())->sum('quantity')
                    + $ledgerByType->get(StockMovement::TYPE_RENTAL_OUT, collect())->sum('quantity')),
                'returns' => (int) ($ledgerByType->get(StockMovement::TYPE_PICKUP_RETURN, collect())->sum('quantity')
                    + $ledgerByType->get(StockMovement::TYPE_RETURN_VERIFICATION, collect())->sum('quantity')),
            ],
            'fallback_quantities' => [
                'sale_out' => (int) ($syntheticByType->get(StockMovement::TYPE_SALE, collect())->sum('quantity')),
                'rental_out' => (int) ($syntheticByType->get(StockMovement::TYPE_DELIVERY, collect())->sum('quantity')
                    + $syntheticByType->get(StockMovement::TYPE_RENTAL_OUT, collect())->sum('quantity')),
                'returns' => (int) ($syntheticByType->get(StockMovement::TYPE_PICKUP_RETURN, collect())->sum('quantity')
                    + $syntheticByType->get(StockMovement::TYPE_RETURN_VERIFICATION, collect())->sum('quantity')),
            ],
            'unlinked_sales' => $synthetic
                ->filter(fn (StockMovement $movement) => $movement->movement_type === StockMovement::TYPE_SALE && empty($movement->sale_id))
                ->count(),
            'unlinked_rentals' => $synthetic
                ->filter(fn (StockMovement $movement) => in_array($movement->movement_type, [
                    StockMovement::TYPE_DELIVERY,
                    StockMovement::TYPE_RENTAL_OUT,
                    StockMovement::TYPE_PICKUP_RETURN,
                    StockMovement::TYPE_RETURN_VERIFICATION,
                ], true) && empty($movement->rental_id))
                ->count(),
            'unlinked_deliveries' => $synthetic
                ->filter(fn (StockMovement $movement) => in_array($movement->movement_type, [
                    StockMovement::TYPE_DELIVERY,
                    StockMovement::TYPE_PICKUP_RETURN,
                ], true) && empty($movement->delivery_id))
                ->count(),
            'warnings' => $warnings->values(),
        ];
    }

    private function assetReconciliation(int $organizationId, array $filters): array
    {
        $assetsQuery = Asset::query()
            ->with(['warehouse:id,name,city', 'product:id,name,category'])
            ->where('organization_id', $organizationId);

        if (($filters['product_id'] ?? null) !== null) {
            $assetsQuery->where('product_id', (int) $filters['product_id']);
        }

        if (($filters['category'] ?? '') !== '') {
            $category = (string) $filters['category'];
            $assetsQuery->whereHas('product', function (Builder $query) use ($category) {
                $this->applyCategoryConstraint($query, $category);
            });
        }

        $mode = strtolower((string) ($filters['mode'] ?? 'all'));
        if ($mode === 'rent') {
            $assetsQuery->where('asset_stage', Asset::STAGE_RENTAL_STOCK);
        } elseif ($mode === 'sale') {
            $assetsQuery->where('asset_stage', Asset::STAGE_NEW_STOCK);
        }

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $assetsQuery->where('warehouse_id', $warehouseId);
        } elseif (($filters['city'] ?? '') !== '') {
            $city = strtolower(trim((string) $filters['city']));
            $assetsQuery->whereHas('warehouse', function (Builder $query) use ($city) {
                $query->whereRaw('LOWER(city) = ?', [$city]);
            });
        }

        $assets = $assetsQuery->get();
        $distribution = $this->assetStateDistribution($assets);

        $warehouseBreakdown = app(AssetStateService::class)
            ->warehouseRentalReconciliation($organizationId, $assetsQuery, $filters);

        $warnings = collect();
        if ($distribution['state_sum'] !== $distribution['total_assets']) {
            $warnings->push([
                'type' => 'asset_state_mismatch',
                'message' => sprintf(
                    'Current asset state buckets add up to %d, but filtered total assets is %d.',
                    $distribution['state_sum'],
                    $distribution['total_assets']
                ),
            ]);
        }

        if ($distribution['unaccounted_assets_count'] > 0) {
            $warnings->push([
                'type' => 'unaccounted_assets',
                'message' => sprintf(
                    '%d asset(s) are outside the tracked reconciliation state buckets.',
                    $distribution['unaccounted_assets_count']
                ),
            ]);
        }

        $rentalReconciliation = app(AssetStateService::class)
            ->rentalReconciliation($organizationId, $assetsQuery, $filters);

        return [
            'total_assets' => $distribution['total_assets'],
            'total_rental_assets' => $assets->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->count(),
            'total_sale_assets' => $assets->where('asset_stage', Asset::STAGE_NEW_STOCK)->count(),
            'states' => $distribution['states'],
            'rental_states' => $this->assetStateDistribution($assets->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->values())['states'],
            'sale_states' => $this->assetStateDistribution($assets->where('asset_stage', Asset::STAGE_NEW_STOCK)->values())['states'],
            'rental_reconciliation' => $rentalReconciliation,
            'state_sum' => $distribution['state_sum'],
            'reconciles' => $distribution['state_sum'] === $distribution['total_assets'],
            'unaccounted_assets_count' => $distribution['unaccounted_assets_count'],
            'unaccounted_assets' => $distribution['unaccounted_assets'],
            'warehouse_breakdown' => $warehouseBreakdown,
            'warnings' => $warnings->values(),
        ];
    }

    private function assetStateDistribution(Collection $assets): array
    {
        $states = [
            'available' => 0,
            'rented' => 0,
            'reserved' => 0,
            'awaiting_verification' => 0,
            'maintenance' => 0,
            'transfer_in_progress' => 0,
            'sold' => 0,
            'retired' => 0,
        ];

        $unaccounted = collect();

        foreach ($assets as $asset) {
            $bucket = match ((string) ($asset->asset_status ?? '')) {
                Asset::STATUS_AVAILABLE,
                Asset::STATUS_AVAILABLE_FOR_SALE => 'available',
                Asset::STATUS_RENTED => 'rented',
                Asset::STATUS_RESERVED,
                Asset::STATUS_RESERVED_FOR_SALE => 'reserved',
                Asset::STATUS_AWAITING_VERIFICATION => 'awaiting_verification',
                Asset::STATUS_MAINTENANCE => 'maintenance',
                Asset::STATUS_SOLD => 'sold',
                Asset::STATUS_RETIRED => 'retired',
                'transfer_in_progress' => 'transfer_in_progress',
                default => null,
            };

            if ($bucket === null) {
                $unaccounted->push([
                    'asset_id' => (int) $asset->id,
                    'product' => $asset->product?->name ?: 'Unknown Product',
                    'serial_number' => $asset->serial_number,
                    'asset_stage' => $asset->asset_stage,
                    'asset_status' => $asset->asset_status,
                    'warehouse' => $asset->warehouse?->name,
                ]);
                continue;
            }

            $states[$bucket]++;
        }

        return [
            'total_assets' => $assets->count(),
            'states' => $states,
            'state_sum' => array_sum($states),
            'unaccounted_assets_count' => $unaccounted->count(),
            'unaccounted_assets' => $unaccounted->values(),
        ];
    }

    private function filterOptions(int $organizationId): array
    {
        return [
            'products' => Product::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'product_type', 'stock_mode']),
            'categories' => Schema::hasTable('product_categories')
                ? ProductCategory::query()
                    ->forOrganization($organizationId)
                    ->active()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
            'warehouses' => Warehouse::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'city']),
            'cities' => Warehouse::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->orderBy('city')
                ->pluck('city'),
        ];
    }

    private function currentRentalUsageByProduct(int $organizationId, Collection $products, array $filters): array
    {
        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($productIds === []) {
            return [];
        }

        $query = Rental::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereIn('product_id', $productIds)
            ->with([
                'rentalItems:id,rental_id,product_id,quantity,ordered_quantity,delivered_quantity,returned_quantity',
                'dispatchWarehouse:id,name,city',
            ]);

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $query->where('dispatch_warehouse_id', $warehouseId);
        }

        $city = strtolower(trim((string) ($filters['city'] ?? '')));
        if ($city !== '') {
            $query->whereHas('dispatchWarehouse', function (Builder $warehouseQuery) use ($city) {
                $warehouseQuery->whereRaw('LOWER(city) = ?', [$city]);
            });
        }

        $usage = [];

        foreach ($query->get() as $rental) {
            $items = $rental->relationLoaded('rentalItems')
                ? $rental->rentalItems
                : collect();

            if ($items->isEmpty()) {
                $productId = (int) $rental->product_id;
                if ($productId > 0 && in_array($productId, $productIds, true)) {
                    $usage[$productId] = ($usage[$productId] ?? 0)
                        + max((int) $rental->deliveredQuantityTotal() - (int) $rental->returnedQuantityTotal(), 0);
                }

                continue;
            }

            foreach ($items as $item) {
                $productId = (int) ($item->product_id ?? 0);
                if ($productId <= 0 || !in_array($productId, $productIds, true)) {
                    continue;
                }

                $delivered = min(
                    max((int) ($item->delivered_quantity ?? 0), 0),
                    max((int) ($item->ordered_quantity ?? $item->quantity ?? 0), 0)
                );
                $returned = min(max((int) ($item->returned_quantity ?? 0), 0), $delivered);
                $inUse = max($delivered - $returned, 0);

                $usage[$productId] = ($usage[$productId] ?? 0) + $inUse;
            }
        }

        return $usage;
    }

    private function rowUtilizationPct(Product $product, int $closingStock, int $currentRentalInUse, array $filters): float
    {
        $hasLocationFilter = (int) ($filters['warehouse_id'] ?? 0) > 0
            || trim((string) ($filters['city'] ?? '')) !== '';

        $effectiveAvailable = $hasLocationFilter
            ? max($closingStock, 0)
            : max((int) ($product->available_quantity ?? $closingStock), 0);

        $basis = $effectiveAvailable + max($currentRentalInUse, 0);

        return $basis > 0
            ? round((max($currentRentalInUse, 0) / $basis) * 100, 1)
            : 0.0;
    }

    private function authoritativeOperationalSummary(
        int $organizationId,
        Collection $products,
        Collection $movements,
        array $filters,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($productIds === []) {
            return [
                'sale_out' => 0,
                'rental_out' => 0,
                'returns' => 0,
                'adjustment_net' => 0,
            ];
        }

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        $city = strtolower(trim((string) ($filters['city'] ?? '')));

        $saleOut = 0;
        $saleOrders = 0;
        $salesQuery = Sale::query()
            ->where('organization_id', $organizationId)
            ->whereDate('sale_date', '>=', $startDate->toDateString())
            ->whereDate('sale_date', '<=', $endDate->toDateString())
            ->where(function (Builder $query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'void');
            })
            ->with(['warehouse:id,name,city']);

        if ($warehouseId > 0) {
            $salesQuery->where('warehouse_id', $warehouseId);
        } elseif ($city !== '') {
            $salesQuery->whereHas('warehouse', function (Builder $query) use ($city) {
                $query->whereRaw('LOWER(city) = ?', [$city]);
            });
        }

        if (Sale::hasSaleItemsTable()) {
            foreach ($salesQuery->with(['saleItems:id,sale_id,product_id,quantity'])->get() as $sale) {
                $matchingItems = $sale->displaySaleItems()
                    ->filter(fn ($item) => in_array((int) ($item->product_id ?? 0), $productIds, true))
                    ->filter(fn ($item) => max((int) ($item->quantity ?? 0), 0) > 0)
                    ->values();

                if ($matchingItems->isNotEmpty()) {
                    $saleOrders++;
                }

                $saleOut += (int) $matchingItems
                    ->sum(fn ($item) => max((int) ($item->quantity ?? 0), 0));
            }
        } else {
            $filteredSalesQuery = (clone $salesQuery)->whereIn('product_id', $productIds);
            $saleOrders = (int) (clone $filteredSalesQuery)->count();
            $saleOut = (int) (clone $filteredSalesQuery)->sum('quantity');
        }

        $rentalOut = 0;
        $returns = 0;
        $deliveryQuery = Delivery::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', $startDate->toDateString())
            ->whereDate('completed_at', '<=', $endDate->toDateString())
            ->with([
                'rental:id,product_id,dispatch_warehouse_id,quantity',
                'rental.dispatchWarehouse:id,name,city',
                'rental.rentalItems:id,rental_id,product_id,quantity,ordered_quantity,delivered_quantity,returned_quantity',
            ]);

        foreach ($deliveryQuery->get() as $delivery) {
            $rental = $delivery->rental;
            if (!$rental) {
                continue;
            }

            $dispatchWarehouse = $rental->dispatchWarehouse;
            if ($warehouseId > 0 && (int) ($rental->dispatch_warehouse_id ?? 0) !== $warehouseId) {
                continue;
            }
            if ($city !== '' && $warehouseId === 0 && strtolower(trim((string) ($dispatchWarehouse->city ?? ''))) !== $city) {
                continue;
            }

            $quantity = 0;
            $items = $rental->relationLoaded('rentalItems') ? $rental->rentalItems : collect();
            if ($items->isNotEmpty()) {
                foreach ($items as $item) {
                    $productId = (int) ($item->product_id ?? 0);
                    if (!in_array($productId, $productIds, true)) {
                        continue;
                    }

                    $ordered = max((int) ($item->ordered_quantity ?? $item->quantity ?? 0), 0);
                    $delivered = min(max((int) ($item->delivered_quantity ?? 0), 0), $ordered);
                    $returned = min(max((int) ($item->returned_quantity ?? 0), 0), $delivered);
                    $quantity += $delivery->type === 'pickup' ? $returned : $delivered;
                }
            } elseif (in_array((int) ($rental->product_id ?? 0), $productIds, true)) {
                $quantity = max((int) ($rental->quantity ?? 0), 0);
            }

            if ($delivery->type === 'pickup') {
                $returns += $quantity;
            } else {
                $rentalOut += $quantity;
            }
        }

        $adjustmentNet = (int) $movements
            ->filter(fn (StockMovement $movement) => optional($movement->movement_at)?->betweenIncluded($startDate, $endDate))
            ->filter(fn (StockMovement $movement) => !in_array($movement->movement_type, [
                StockMovement::TYPE_OPENING,
                StockMovement::TYPE_SALE,
                StockMovement::TYPE_DELIVERY,
                StockMovement::TYPE_RENTAL_OUT,
                StockMovement::TYPE_PICKUP_RETURN,
            ], true))
            ->sum(fn (StockMovement $movement) => $this->availableDelta($movement, $filters));

        return [
            'sale_orders' => $saleOrders,
            'sale_out' => $saleOut,
            'rental_out' => $rentalOut,
            'returns' => $returns,
            'adjustment_net' => $adjustmentNet,
        ];
    }

    private function syntheticOperationalMovements(int $organizationId, Collection $products, Collection $existingMovements, Carbon $endDate, array $filters): Collection
    {
        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($productIds === []) {
            return collect();
        }

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        $city = strtolower(trim((string) ($filters['city'] ?? '')));

        $existingSaleKeys = array_fill_keys(
            $existingMovements
                ->filter(fn (StockMovement $movement) => $movement->movement_type === StockMovement::TYPE_SALE)
                ->map(fn (StockMovement $movement) => ((int) ($movement->sale_id ?? 0)).'|'.((int) ($movement->product_id ?? 0)))
                ->filter()
                ->all(),
            true
        );

        $existingDeliveryKeys = array_fill_keys(
            $existingMovements
                ->filter(fn (StockMovement $movement) => in_array($movement->movement_type, [
                    StockMovement::TYPE_DELIVERY,
                    StockMovement::TYPE_RENTAL_OUT,
                    StockMovement::TYPE_PICKUP_RETURN,
                    StockMovement::TYPE_RETURN_VERIFICATION,
                ], true))
                ->map(function (StockMovement $movement) {
                    $bucket = in_array($movement->movement_type, [StockMovement::TYPE_DELIVERY, StockMovement::TYPE_RENTAL_OUT], true)
                        ? 'delivery'
                        : 'pickup';

                    return ((int) ($movement->delivery_id ?? 0)).'|'.((int) ($movement->product_id ?? 0)).'|'.$bucket;
                })
                ->filter()
                ->all(),
            true
        );

        $synthetic = collect();

        $salesQuery = Sale::query()
            ->where('organization_id', $organizationId)
            ->whereDate('sale_date', '<=', $endDate->toDateString())
            ->where(function (Builder $query) {
                $query->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'void');
            })
            ->with(['warehouse:id,name,city']);

        if ($warehouseId > 0) {
            $salesQuery->where('warehouse_id', $warehouseId);
        } elseif ($city !== '') {
            $salesQuery->whereHas('warehouse', function (Builder $query) use ($city) {
                $query->whereRaw('LOWER(city) = ?', [$city]);
            });
        }

        if (Sale::hasSaleItemsTable()) {
            $sales = $salesQuery->with(['saleItems:id,sale_id,product_id,quantity'])->get();
            foreach ($sales as $sale) {
                foreach ($sale->displaySaleItems() as $item) {
                    $productId = (int) ($item->product_id ?? 0);
                    if ($productId <= 0 || !in_array($productId, $productIds, true)) {
                        continue;
                    }

                    $key = ((int) $sale->id).'|'.$productId;
                    if (isset($existingSaleKeys[$key])) {
                        continue;
                    }

                    $product = $products->firstWhere('id', $productId);
                    if (!$product) {
                        continue;
                    }

                    $synthetic->push($this->makeSyntheticMovement([
                        'organization_id' => $organizationId,
                        'product_id' => $productId,
                        'sale_id' => (int) $sale->id,
                        'quantity' => max((int) ($item->quantity ?? 0), 0),
                        'movement_type' => StockMovement::TYPE_SALE,
                        'movement_at' => Carbon::parse($sale->sale_date ?: $sale->created_at ?: now()),
                        'from_status' => 'available',
                        'to_status' => 'sold',
                        'from_warehouse_id' => (int) ($sale->warehouse_id ?? 0) ?: null,
                        'performed_by_user_id' => (int) ($sale->created_by ?? $sale->user_id ?? 0) ?: null,
                        'notes' => 'Synthetic sale movement from legacy sale record.',
                    ], [
                        'product' => $product,
                        'sale' => $sale,
                        'fromWarehouse' => $sale->warehouse,
                    ]));
                }
            }
        } else {
            foreach ($salesQuery->whereIn('product_id', $productIds)->get() as $sale) {
                $productId = (int) ($sale->product_id ?? 0);
                $key = ((int) $sale->id).'|'.$productId;
                if ($productId <= 0 || isset($existingSaleKeys[$key])) {
                    continue;
                }

                $product = $products->firstWhere('id', $productId);
                if (!$product) {
                    continue;
                }

                $synthetic->push($this->makeSyntheticMovement([
                    'organization_id' => $organizationId,
                    'product_id' => $productId,
                    'sale_id' => (int) $sale->id,
                    'quantity' => max((int) ($sale->quantity ?? 0), 0),
                    'movement_type' => StockMovement::TYPE_SALE,
                    'movement_at' => Carbon::parse($sale->sale_date ?: $sale->created_at ?: now()),
                    'from_status' => 'available',
                    'to_status' => 'sold',
                    'from_warehouse_id' => (int) ($sale->warehouse_id ?? 0) ?: null,
                    'performed_by_user_id' => (int) ($sale->created_by ?? $sale->user_id ?? 0) ?: null,
                    'notes' => 'Synthetic sale movement from legacy sale record.',
                ], [
                    'product' => $product,
                    'sale' => $sale,
                    'fromWarehouse' => $sale->warehouse,
                ]));
            }
        }

        $deliveryQuery = Delivery::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '<=', $endDate->toDateString())
            ->with([
                'rental:id,product_id,dispatch_warehouse_id,quantity,customer_id,customer_name',
                'rental.customer:id,name,phone,city',
                'rental.dispatchWarehouse:id,name,city',
                'rental.rentalItems:id,rental_id,product_id,quantity,ordered_quantity,delivered_quantity,returned_quantity',
            ]);

        foreach ($deliveryQuery->get() as $delivery) {
            $rental = $delivery->rental;
            if (!$rental) {
                continue;
            }

            $dispatchWarehouse = $rental->dispatchWarehouse;
            if ($warehouseId > 0 && (int) ($rental->dispatch_warehouse_id ?? 0) !== $warehouseId) {
                continue;
            }
            if ($city !== '' && $warehouseId === 0 && strtolower(trim((string) ($dispatchWarehouse->city ?? ''))) !== $city) {
                continue;
            }

            $items = $rental->relationLoaded('rentalItems') ? $rental->rentalItems : collect();
            $bucket = $delivery->type === 'pickup' ? 'pickup' : 'delivery';

            if ($items->isNotEmpty()) {
                foreach ($items as $item) {
                    $productId = (int) ($item->product_id ?? 0);
                    if ($productId <= 0 || !in_array($productId, $productIds, true)) {
                        continue;
                    }

                    $key = ((int) $delivery->id).'|'.$productId.'|'.$bucket;
                    if (isset($existingDeliveryKeys[$key])) {
                        continue;
                    }

                    $ordered = max((int) ($item->ordered_quantity ?? $item->quantity ?? 0), 0);
                    $delivered = min(max((int) ($item->delivered_quantity ?? 0), 0), $ordered);
                    $returned = min(max((int) ($item->returned_quantity ?? 0), 0), $delivered);
                    $quantity = $bucket === 'pickup' ? $returned : $delivered;

                    if ($quantity <= 0) {
                        continue;
                    }

                    $product = $products->firstWhere('id', $productId);
                    if (!$product) {
                        continue;
                    }

                    $synthetic->push($this->makeSyntheticMovement([
                        'organization_id' => $organizationId,
                        'product_id' => $productId,
                        'rental_id' => (int) $rental->id,
                        'delivery_id' => (int) $delivery->id,
                        'quantity' => $quantity,
                        'movement_type' => $bucket === 'pickup' ? StockMovement::TYPE_PICKUP_RETURN : StockMovement::TYPE_DELIVERY,
                        'movement_at' => Carbon::parse($delivery->completed_at ?: now()),
                        'from_status' => $bucket === 'pickup' ? 'with_customer' : 'available',
                        'to_status' => $bucket === 'pickup' ? 'available' : 'with_customer',
                        'from_warehouse_id' => $bucket === 'pickup' ? null : ((int) ($rental->dispatch_warehouse_id ?? 0) ?: null),
                        'to_warehouse_id' => $bucket === 'pickup' ? ((int) ($rental->dispatch_warehouse_id ?? 0) ?: null) : null,
                        'performed_by_user_id' => (int) ($delivery->assigned_user_id ?? 0) ?: null,
                        'notes' => $bucket === 'pickup'
                            ? 'Synthetic pickup return movement from legacy completed pickup.'
                            : 'Synthetic rental out movement from legacy completed delivery.',
                    ], [
                        'product' => $product,
                        'delivery' => $delivery,
                        'rental' => $rental,
                        'fromWarehouse' => $bucket === 'pickup' ? null : $dispatchWarehouse,
                        'toWarehouse' => $bucket === 'pickup' ? $dispatchWarehouse : null,
                    ]));
                }

                continue;
            }

            $productId = (int) ($rental->product_id ?? 0);
            if ($productId <= 0 || !in_array($productId, $productIds, true)) {
                continue;
            }

            $key = ((int) $delivery->id).'|'.$productId.'|'.$bucket;
            if (isset($existingDeliveryKeys[$key])) {
                continue;
            }

            $quantity = max((int) ($rental->quantity ?? 0), 0);
            if ($quantity <= 0) {
                continue;
            }

            $product = $products->firstWhere('id', $productId);
            if (!$product) {
                continue;
            }

            $synthetic->push($this->makeSyntheticMovement([
                'organization_id' => $organizationId,
                'product_id' => $productId,
                'rental_id' => (int) $rental->id,
                'delivery_id' => (int) $delivery->id,
                'quantity' => $quantity,
                'movement_type' => $bucket === 'pickup' ? StockMovement::TYPE_PICKUP_RETURN : StockMovement::TYPE_DELIVERY,
                'movement_at' => Carbon::parse($delivery->completed_at ?: now()),
                'from_status' => $bucket === 'pickup' ? 'with_customer' : 'available',
                'to_status' => $bucket === 'pickup' ? 'available' : 'with_customer',
                'from_warehouse_id' => $bucket === 'pickup' ? null : ((int) ($rental->dispatch_warehouse_id ?? 0) ?: null),
                'to_warehouse_id' => $bucket === 'pickup' ? ((int) ($rental->dispatch_warehouse_id ?? 0) ?: null) : null,
                'performed_by_user_id' => (int) ($delivery->assigned_user_id ?? 0) ?: null,
                'notes' => $bucket === 'pickup'
                    ? 'Synthetic pickup return movement from legacy completed pickup.'
                    : 'Synthetic rental out movement from legacy completed delivery.',
            ], [
                'product' => $product,
                'delivery' => $delivery,
                'rental' => $rental,
                'fromWarehouse' => $bucket === 'pickup' ? null : $dispatchWarehouse,
                'toWarehouse' => $bucket === 'pickup' ? $dispatchWarehouse : null,
            ]));
        }

        return $synthetic->values();
    }

    private function makeSyntheticMovement(array $attributes, array $relations = []): StockMovement
    {
        $movement = new StockMovement($attributes);
        $movement->exists = false;

        foreach ($relations as $relation => $value) {
            $movement->setRelation($relation, $value);
        }

        return $movement;
    }
}
